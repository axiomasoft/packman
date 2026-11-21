<?php

declare(strict_types=1);

namespace Packman\Filament\Forms;

use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use JsonException;
use Packman\Attributes\Api;
use Packman\Filament\Exceptions\Binding\BindingAuthorizationException;
use Packman\Filament\Exceptions\Binding\BindingConflictException;
use Packman\Filament\Exceptions\Binding\InvalidBindingStateException;
use Packman\Panels\Contracts\Registry\PanelRegistry;
use Packman\Panels\Contracts\Subjects\PanelFormProjector;
use Packman\Panels\Contracts\Subjects\SubjectChainResolver;
use Packman\Panels\Data\Panel\PanelFormData;
use Packman\Panels\Data\Subjects\SubjectNode;
use Packman\Panels\Exceptions\Permissions\PolicyConflictException;
use Packman\Panels\Exceptions\Subjects\SubjectResolutionFailed;

/**
 * Stateless AES-sealed Filament binding form helper with server-side editability and optimistic concurrency enforcement.
 *
 * @phpstan-type PreparedBindingSave array{
 *     tenant: string,
 *     target: string,
 *     panel: string,
 *     slot: string,
 *     subject: SubjectNode,
 *     projection: PanelFormData,
 *     changes: array<string, array{operation: string, value: mixed}>,
 *     reason: string,
 *     revision: int,
 *     edit_version: string,
 *     authority_version: int,
 * }
 * @phpstan-type DescriptorRule array{nullable?: bool, optional?: bool, minimum?: int|float, maximum?: int|float}
 */
#[Api(since: '1.0')]
final readonly class BindingEditor
{
    public function __construct(
        private PanelRegistry $panels,
        private PanelFormProjector $projector,
        private SubjectChainResolver $subjects,
    ) {}

    public static function fieldId(string $key): string
    {
        return bin2hex($key);
    }

    public static function decodeFieldId(string $id): ?string
    {
        if ($id === '' || strlen($id) % 2 !== 0 || ! ctype_xdigit($id)) {
            return null;
        }

        $decoded = hex2bin($id);

        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }

    /**
     * @throws BindingAuthorizationException
     */
    public function project(string $expectedTenantId, string $panelId, SubjectNode $subject, string $slotKey): PanelFormData
    {
        if (! hash_equals($expectedTenantId, $subject->tenantId)) {
            throw BindingAuthorizationException::invalidContext('Subject belongs to another tenant.');
        }

        return $this->projector->project($this->panels->get($panelId), $subject, $slotKey);
    }

    /**
     * Opens a stateless editing session with an AES-encrypted Livewire context payload.
     *
     * @return array{context: string, fields: array<string, array{mode: string, value: mixed}>, reason: string}
     *
     * @throws BindingAuthorizationException
     */
    public function open(
        string $expectedTenantId,
        string $panelId,
        SubjectNode $subject,
        string $slotKey,
        string $targetId,
        int $authorityVersion = 0,
        ?PanelFormData $projection = null,
    ): array {
        $projection ??= $this->project($expectedTenantId, $panelId, $subject, $slotKey);

        if ($projection->blockedReason !== null) {
            return ['context' => '', 'fields' => [], 'reason' => ''];
        }

        $fields = [];
        $binding = is_array($projection->binding) ? $projection->binding : [];
        $retired = (bool) ($binding['retired'] ?? false);
        $patch = is_array($binding['patch'] ?? null) ? $binding['patch'] : [];
        /** @var array<string, mixed> $localChanges */
        $localChanges = is_array($patch['changes'] ?? null) ? $patch['changes'] : [];

        foreach ($projection->fields as $key => $field) {
            $local = $retired ? null : ($localChanges[$key] ?? null);
            $localOp = is_array($local) ? ($local['operation'] ?? null) : null;
            $localHasValue = is_array($local) && array_key_exists('value', $local);
            $localVal = $localHasValue ? $local['value'] : null;

            $mode = match (true) {
                $local === null => 'inherit',
                $localOp === 'disable' => 'disable',
                $localVal === null => 'null',
                default => 'set',
            };

            $fields[self::fieldId($key)] = [
                'mode' => $mode,
                'value' => $local !== null && $localOp !== 'disable' && $localOp !== 'inherit' ? $localVal : $field['value'],
            ];
        }

        $chain = $this->subjects->resolve($subject);
        $rawRevision = $binding['revision'] ?? 0;
        $revision = is_int($rawRevision) ? $rawRevision : 0;

        $payload = [
            'tenant' => $subject->tenantId,
            'type' => $subject->type,
            'id' => $subject->id,
            'target' => $targetId,
            'panel' => $panelId,
            'slot' => $slotKey,
            'revision' => $revision,
            'edit_version' => $projection->editVersion ?? '',
            'chain' => array_map(static fn (SubjectNode $node): string => $node->key(), $chain->ordered),
            'authority_version' => $authorityVersion,
        ];

        return [
            'context' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
            'fields' => $fields,
            'reason' => '',
        ];
    }

    /**
     * Unseals context, verifies concurrency and chain integrity, enforces server-side editability, and validates reason.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, DescriptorRule>  $descriptorRules
     * @return PreparedBindingSave
     *
     * @throws BindingAuthorizationException
     * @throws BindingConflictException
     * @throws InvalidBindingStateException
     * @throws SubjectResolutionFailed
     * @throws ValidationException
     */
    public function prepareSave(array $data, string $expectedTenantId, array $descriptorRules = []): array
    {
        $unsealed = self::unsealContext($data['context'] ?? null);

        if (! hash_equals($expectedTenantId, $unsealed['tenant'])) {
            throw BindingAuthorizationException::invalidContext('Encrypted binding context tenant mismatch.');
        }

        $subject = new SubjectNode($unsealed['tenant'], $unsealed['type'], $unsealed['id']);
        $projection = $this->project($expectedTenantId, $unsealed['panel'], $subject, $unsealed['slot']);

        if ($projection->blockedReason !== null) {
            throw new SubjectResolutionFailed($projection->blockedReason);
        }

        $liveChain = array_map(
            static fn (SubjectNode $node): string => $node->key(),
            $this->subjects->resolve($subject)->ordered,
        );

        $editVersion = $projection->editVersion ?? '';

        if ($unsealed['edit_version'] !== $editVersion || $unsealed['chain'] !== $liveChain) {
            throw BindingConflictException::staleVersion($unsealed['edit_version'], $editVersion);
        }

        $submittedFields = $data['fields'] ?? null;
        $expectedIds = array_map(self::fieldId(...), array_keys($projection->fields));

        if (! is_array($submittedFields) || array_diff(array_keys($submittedFields), $expectedIds) !== []) {
            throw InvalidBindingStateException::invalidPatch('Unexpected field identifier in binding payload.');
        }

        $binding = is_array($projection->binding) ? $projection->binding : [];
        $retired = (bool) ($binding['retired'] ?? false);
        $patch = is_array($binding['patch'] ?? null) ? $binding['patch'] : [];
        /** @var array<string, mixed> $localChanges */
        $localChanges = ! $retired && is_array($patch['changes'] ?? null)
            ? $patch['changes']
            : [];

        $changes = [];
        $editable = false;

        foreach ($projection->fields as $field) {
            if ($field['editable']) {
                $editable = true;

                break;
            }
        }

        if (! $editable) {
            throw BindingAuthorizationException::nonEditable('form');
        }

        foreach ($projection->fields as $key => $field) {
            $state = $submittedFields[self::fieldId($key)] ?? null;

            // Filament omits disabled fields from dehydrated form state; never misinterpret null as tamper.
            if ($state === null && ! $field['editable']) {
                continue;
            }

            if (! is_array($state)) {
                throw InvalidBindingStateException::invalidPatch('Field state for "'.$key.'" must be an array.');
            }

            $rule = $descriptorRules[$key] ?? [];
            $normalized = self::normalizeSubmittedChange($key, $state, $rule);
            $local = is_array($localChanges[$key] ?? null) ? $localChanges[$key] : null;

            if (self::matchesLocalChange($normalized, $local)) {
                continue;
            }

            if (! $field['editable']) {
                throw BindingAuthorizationException::nonEditable($key);
            }

            $changes[$key] = $normalized;
        }

        $reason = $data['reason'] ?? '';
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'max:512']])->validate();

        return [
            'tenant' => $unsealed['tenant'],
            'target' => $unsealed['target'],
            'panel' => $unsealed['panel'],
            'slot' => $unsealed['slot'],
            'subject' => $subject,
            'projection' => $projection,
            'changes' => $changes,
            'reason' => is_string($reason) ? $reason : '',
            'revision' => $unsealed['revision'],
            'edit_version' => $unsealed['edit_version'],
            'authority_version' => $unsealed['authority_version'],
        ];
    }

    /**
     * Validates a Filament submission and commits normalized changes, converting domain conflicts into ValidationException.
     *
     * @param  array<string, mixed>  $data
     * @param  Closure(PreparedBindingSave): void  $commit
     * @param  array<string, DescriptorRule>  $descriptorRules
     *
     * @throws BindingAuthorizationException
     * @throws ValidationException
     */
    public function save(array $data, string $expectedTenantId, Closure $commit, array $descriptorRules = []): void
    {
        try {
            $prepared = $this->prepareSave($data, $expectedTenantId, $descriptorRules);
            $commit($prepared);
        } catch (BindingConflictException|PolicyConflictException) {
            throw ValidationException::withMessages(['data' => 'Policy state has changed. Reload the form.']);
        } catch (InvalidBindingStateException) {
            throw ValidationException::withMessages(['data' => 'Invalid setting operation or value.']);
        } catch (SubjectResolutionFailed) {
            throw ValidationException::withMessages(['data' => 'Subject is unavailable or its ancestry chain is broken.']);
        }
    }

    /**
     * Builds Filament schema components for a binding form section from a projected `PanelFormData`.
     *
     * @return list<Component>
     */
    public static function schemaComponents(
        PanelFormData $projection,
        bool $includeReason = true,
        string $sectionHeading = 'Settings',
    ): array {
        $fieldComponents = [];

        foreach ($projection->fields as $key => $field) {
            $id = self::fieldId($key);
            $helper = 'Source: '.$field['source'];

            if ($field['clamped']) {
                $min = $field['minimum'] !== null ? (string) $field['minimum'] : '-inf';
                $max = $field['maximum'] !== null ? (string) $field['maximum'] : '+inf';
                $helper .= ' (clamped ['.$min.'..'.$max.'])';
            }

            $fieldComponents[] = Section::make($key)
                ->schema([
                    Select::make('fields.'.$id.'.mode')
                        ->label('Mode')
                        ->options([
                            'inherit' => 'Inherit',
                            'set' => 'Set value',
                            'null' => 'Null',
                            'disable' => 'Disable',
                        ])
                        ->disabled(! $field['editable']),
                    TextInput::make('fields.'.$id.'.value')
                        ->label('Value')
                        ->disabled(! $field['editable'])
                        ->helperText($helper),
                ]);
        }

        $components = [
            Hidden::make('context'),
            Section::make($sectionHeading)->schema($fieldComponents),
        ];

        if ($includeReason) {
            $components[] = TextInput::make('reason')
                ->label('Reason')
                ->required()
                ->maxLength(512);
        }

        return $components;
    }

    /**
     * @return array{
     *     tenant: string,
     *     type: string,
     *     id: string,
     *     target: string,
     *     panel: string,
     *     slot: string,
     *     revision: int,
     *     edit_version: string,
     *     chain: list<string>,
     *     authority_version: int,
     * }
     *
     * @throws BindingAuthorizationException
     */
    private static function unsealContext(mixed $ciphertext): array
    {
        if (! is_string($ciphertext) || $ciphertext === '') {
            throw BindingAuthorizationException::invalidContext();
        }

        try {
            $decoded = json_decode(Crypt::decryptString($ciphertext), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw BindingAuthorizationException::invalidContext();
        }

        if (! is_array($decoded)) {
            throw BindingAuthorizationException::invalidContext();
        }

        $tenant = $decoded['tenant'] ?? null;
        $type = $decoded['type'] ?? null;
        $id = $decoded['id'] ?? null;
        $target = $decoded['target'] ?? null;
        $panel = $decoded['panel'] ?? null;
        $slot = $decoded['slot'] ?? null;
        $revision = $decoded['revision'] ?? null;
        $editVersion = $decoded['edit_version'] ?? null;
        $chain = $decoded['chain'] ?? null;
        $authorityVersion = $decoded['authority_version'] ?? 0;

        if (! is_string($tenant) || ! is_string($type) || ! is_string($id) || ! is_string($target)
            || ! is_string($panel) || ! is_string($slot) || ! is_int($revision) || ! is_string($editVersion)
            || ! is_array($chain) || ! is_int($authorityVersion)) {
            throw BindingAuthorizationException::invalidContext();
        }

        $chainStrings = [];

        foreach ($chain as $entry) {
            if (! is_string($entry)) {
                throw BindingAuthorizationException::invalidContext();
            }

            $chainStrings[] = $entry;
        }

        return [
            'tenant' => $tenant,
            'type' => $type,
            'id' => $id,
            'target' => $target,
            'panel' => $panel,
            'slot' => $slot,
            'revision' => $revision,
            'edit_version' => $editVersion,
            'chain' => $chainStrings,
            'authority_version' => $authorityVersion,
        ];
    }

    /**
     * @param  array<mixed, mixed>  $state
     * @param  DescriptorRule  $rule
     * @return array{operation: string, value: mixed}
     */
    private static function normalizeSubmittedChange(string $key, array $state, array $rule): array
    {
        $mode = $state['mode'] ?? '';

        return match ($mode) {
            'inherit' => ['operation' => 'inherit', 'value' => null],
            'null' => ($rule['nullable'] ?? false) === true
                ? ['operation' => 'set', 'value' => null]
                : throw InvalidBindingStateException::invalidPatch('Field "'.$key.'" does not allow null.'),
            'disable' => ($rule['optional'] ?? false) === true
                ? ['operation' => 'disable', 'value' => null]
                : throw InvalidBindingStateException::invalidPatch('Field "'.$key.'" is not optional.'),
            'set' => self::setValue($key, $state, $rule),
            default => throw InvalidBindingStateException::invalidPatch('Unsupported mode for field "'.$key.'".'),
        };
    }

    /**
     * @param  array<mixed, mixed>  $state
     * @param  DescriptorRule  $rule
     * @return array{operation: string, value: mixed}
     */
    private static function setValue(string $key, array $state, array $rule): array
    {
        $value = $state['value'] ?? null;
        $nullable = ($rule['nullable'] ?? false) === true;

        if ($value === null) {
            if (! $nullable) {
                throw InvalidBindingStateException::invalidPatch('Field "'.$key.'" does not allow null.');
            }

            return ['operation' => 'set', 'value' => null];
        }

        $minimum = $rule['minimum'] ?? null;
        $maximum = $rule['maximum'] ?? null;

        if ($minimum !== null || $maximum !== null) {
            if (! is_int($value) && (! is_float($value) || ! is_finite($value))) {
                throw InvalidBindingStateException::invalidPatch('Field "'.$key.'" must be a finite number.');
            }

            if ($minimum !== null && $value < $minimum) {
                throw InvalidBindingStateException::invalidPatch('Field "'.$key.'" is below the minimum.');
            }

            if ($maximum !== null && $value > $maximum) {
                throw InvalidBindingStateException::invalidPatch('Field "'.$key.'" is above the maximum.');
            }
        }

        return ['operation' => 'set', 'value' => $value];
    }

    /**
     * @param  array{operation: string, value: mixed}  $submitted
     * @param  array<mixed, mixed>|null  $local
     */
    private static function matchesLocalChange(array $submitted, ?array $local): bool
    {
        if ($local === null) {
            return $submitted['operation'] === 'inherit';
        }

        return ($local['operation'] ?? null) === $submitted['operation']
            && array_key_exists('value', $local)
            && $local['value'] === $submitted['value'];
    }
}
