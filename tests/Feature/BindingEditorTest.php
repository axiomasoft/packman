<?php

declare(strict_types=1);

use Filament\Schemas\Components\Section;
use Illuminate\Validation\ValidationException;
use Packman\Filament\Exceptions\Binding\BindingAuthorizationException;
use Packman\Filament\Forms\BindingEditor;
use Packman\Panels\Builder\PanelBuilder;
use Packman\Panels\Builder\PanelProvider;
use Packman\Panels\Contracts\Admission\PanelPolicyResolver;
use Packman\Panels\Contracts\Registry\PanelRegistry;
use Packman\Panels\Contracts\Subjects\PanelSubjectResolver;
use Packman\Panels\Data\Panel\PanelSlot;
use Packman\Panels\Data\Subjects\SubjectChain;
use Packman\Panels\Data\Subjects\SubjectNode;
use Packman\Panels\Internal\Catalogue\PanelRegistryImpl;
use Packman\Panels\Internal\Subjects\PanelFormProjection;
use Packman\Panels\Internal\Subjects\PanelSubjects;

class BindingEditorTestPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'proxy';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel
            ->label('Proxy')
            ->slots([new PanelSlot(
                'read',
                ['timeout.ms', 'strategy', 'locked.flag'],
                ['timeout.ms' => 250, 'strategy' => 'fastest', 'locked.flag' => true],
            )]);
    }
}

/**
 * @return array{
 *     editor: BindingEditor,
 *     subject: SubjectNode,
 *     state: object,
 *     originalRegistry: PanelRegistry,
 * }
 */
function bindingEditorHarness(): array
{
    $originalRegistry = app()->make(PanelRegistry::class);
    $registry = new PanelRegistryImpl(app());
    $registry->register(BindingEditorTestPanelProvider::class);
    $registry->freeze();

    $tenant = new SubjectNode('tenant-a', 'tenant', 'tenant-a');
    $user = new SubjectNode('tenant-a', 'user', 'user-1', [$tenant]);

    $state = new class
    {
        public string $editVersion = 'v-1';

        public bool $parentTamper = false;

        public bool $lockedEditable = false;

        /** @var array<string, mixed>|null */
        public ?array $binding = [
            'revision' => 2,
            'retired' => false,
            'patch' => [
                'changes' => [
                    'timeout.ms' => ['operation' => 'set', 'value' => 400],
                ],
            ],
        ];
    };

    $subjectResolver = new readonly class($tenant, $user, $state) implements PanelSubjectResolver
    {
        public function __construct(
            private SubjectNode $tenant,
            private SubjectNode $user,
            private object $state,
        ) {}

        public function resolve(SubjectNode $subject): ?SubjectChain
        {
            if ($subject->key() === $this->tenant->key()) {
                return new SubjectChain($this->tenant);
            }

            if ($subject->key() === $this->user->withoutParents()->key()) {
                $parents = $this->state->parentTamper
                    ? [new SubjectNode('tenant-a', 'tenant', 'tenant-other')]
                    : [$this->tenant];

                return new SubjectChain($this->user->withoutParents(), $parents);
            }

            return null;
        }
    };

    $policyResolver = new readonly class($state) implements PanelPolicyResolver
    {
        public function __construct(private object $state) {}

        public function canView(SubjectChain $chain, string $panelKey, string $slotKey): bool
        {
            return true;
        }

        public function canUpdate(SubjectChain $chain, string $panelKey, string $slotKey): bool
        {
            return true;
        }

        public function editVersion(string $tenantId): string
        {
            return $this->state->editVersion;
        }

        public function findBinding(SubjectChain $chain, string $panelKey, string $slotKey): ?array
        {
            return $this->state->binding;
        }

        public function resolveFields(SubjectChain $chain, PanelSlot $slot, string $panelKey): array
        {
            return [
                'timeout.ms' => ['value' => 400, 'inherited' => false, 'source' => 'binding@r2', 'revision' => 2, 'editable' => true],
                'strategy' => ['value' => 'fastest', 'inherited' => true, 'source' => 'slot_defaults', 'revision' => 1, 'editable' => true],
                'locked.flag' => ['value' => true, 'inherited' => true, 'source' => 'slot_defaults', 'revision' => 1, 'editable' => $this->state->lockedEditable],
            ];
        }
    };

    $subjects = new PanelSubjects($subjectResolver);
    $projector = new PanelFormProjection($subjects, $policyResolver);
    $editor = new BindingEditor($registry, $projector, $subjects);

    return [
        'editor' => $editor,
        'subject' => $user,
        'state' => $state,
        'originalRegistry' => $originalRegistry,
    ];
}

it('seals AES context on open, accepts valid changes while skipping omitted disabled fields, and builds Filament components', function (): void {
    $harness = bindingEditorHarness();
    $editor = $harness['editor'];
    $subject = $harness['subject'];

    expect(BindingEditor::decodeFieldId(BindingEditor::fieldId('timeout.ms')))->toBe('timeout.ms')
        ->and(BindingEditor::decodeFieldId('zzzz'))->toBeNull();

    $projection = $editor->project('tenant-a', 'proxy', $subject, 'read');
    $components = BindingEditor::schemaComponents($projection);

    expect($components)->toHaveCount(3)
        ->and($components[1])->toBeInstanceOf(Section::class);

    $opened = $editor->open('tenant-a', 'proxy', $subject, 'read', 'target-1', 7, $projection);
    $timeoutId = BindingEditor::fieldId('timeout.ms');
    $strategyId = BindingEditor::fieldId('strategy');
    $lockedId = BindingEditor::fieldId('locked.flag');

    expect($opened['context'])->not->toBe('')
        ->and($opened['fields'][$timeoutId]['mode'])->toBe('set')
        ->and($opened['fields'][$timeoutId]['value'])->toBe(400)
        ->and($opened['fields'][$strategyId]['mode'])->toBe('inherit');

    // Simulate Filament omitting non-editable disabled fields from dehydrated payload.
    unset($opened['fields'][$lockedId]);
    $opened['fields'][$strategyId] = ['mode' => 'set', 'value' => 'round_robin'];
    $opened['fields'][$timeoutId] = ['mode' => 'null', 'value' => null];
    $opened['reason'] = 'Tuned routing strategy';

    $captured = null;
    $editor->save(
        $opened,
        'tenant-a',
        function (array $prepared) use (&$captured): void {
            $captured = $prepared;
        },
        ['timeout.ms' => ['nullable' => true]],
    );

    expect($captured)->not->toBeNull()
        ->and($captured['reason'])->toBe('Tuned routing strategy')
        ->and($captured['authority_version'])->toBe(7)
        ->and($captured['changes'])->toBe([
            'timeout.ms' => ['operation' => 'set', 'value' => null],
            'strategy' => ['operation' => 'set', 'value' => 'round_robin'],
        ]);
});

it('refuses cross-tenant, tampered ciphertext, and non-editable field modifications', function (): void {
    $harness = bindingEditorHarness();
    $editor = $harness['editor'];
    $subject = $harness['subject'];

    expect(fn () => $editor->project('tenant-other', 'proxy', $subject, 'read'))
        ->toThrow(BindingAuthorizationException::class);

    $opened = $editor->open('tenant-a', 'proxy', $subject, 'read', 'target-1');
    $opened['reason'] = 'Attempt tamper';

    expect(fn () => $editor->save(['context' => 'corrupted', 'fields' => []], 'tenant-a', static function (): void {}))
        ->toThrow(BindingAuthorizationException::class);

    expect(fn () => $editor->save($opened, 'tenant-other', static function (): void {}))
        ->toThrow(BindingAuthorizationException::class);

    $tamperedNonEditable = $opened;
    $tamperedNonEditable['fields'][BindingEditor::fieldId('locked.flag')] = ['mode' => 'set', 'value' => false];

    expect(fn () => $editor->save($tamperedNonEditable, 'tenant-a', static function (): void {}))
        ->toThrow(BindingAuthorizationException::class, 'not editable');
});

it('converts stale editVersion and unexpected fields into ValidationException', function (): void {
    $harness = bindingEditorHarness();
    $editor = $harness['editor'];
    $subject = $harness['subject'];

    $opened = $editor->open('tenant-a', 'proxy', $subject, 'read', 'target-1');
    $opened['reason'] = 'Stale attempt';

    $badKey = $opened;
    $badKey['fields'][BindingEditor::fieldId('unknown.setting')] = ['mode' => 'set', 'value' => 1];
    expect(fn () => $editor->save($badKey, 'tenant-a', static function (): void {}))
        ->toThrow(ValidationException::class);

    $harness['state']->editVersion = 'v-2';
    expect(fn () => $editor->save($opened, 'tenant-a', static function (): void {}))
        ->toThrow(ValidationException::class);
});
