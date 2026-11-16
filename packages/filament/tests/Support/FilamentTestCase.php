<?php

declare(strict_types=1);

namespace Packman\Filament\Tests\Support;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Panel as FilamentPanel;
use Filament\PanelRegistry as FilamentPanelRegistry;
use Filament\QueryBuilder\QueryBuilderServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Livewire\LivewireServiceProvider;
use Packman\Tests\TestCase;
use ReflectionClass;

abstract class FilamentTestCase extends TestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            ActionsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            QueryBuilderServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('session.driver', 'array');
        $app['config']->set('auth.providers.users.model', FilamentTestUser::class);

        $compiled = sys_get_temp_dir().'/packman_filament_views_'.getmypid();

        if (! is_dir($compiled)) {
            mkdir($compiled, 0777, true);
        }

        $app['config']->set('view.compiled', $compiled);
    }

    protected function setUp(): void
    {
        parent::setUp();

        View::addLocation(__DIR__.'/../Fixtures/views');
    }

    protected function createFilamentTables(): void
    {
        Schema::dropIfExists('order_product');
        Schema::dropIfExists('products');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('number')->nullable();
            $table->boolean('secret')->default(false);
            $table->boolean('locked')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('order_product', function (Blueprint $table): void {
            $table->foreignId('order_id');
            $table->foreignId('product_id');
        });
    }

    protected function activatePanel(FilamentPanel $panel): void
    {
        app(FilamentPanelRegistry::class)->register($panel);

        $routes = dirname((string) (new ReflectionClass(FilamentServiceProvider::class))->getFileName()).'/../routes/web.php';

        if (is_file($routes)) {
            require $routes;
            app(Router::class)->getRoutes()->refreshNameLookups();
        }

        Filament::setCurrentPanel($panel);
        Filament::bootCurrentPanel();
    }
}
