<?php
/** Actual API source producer/consumer for the owned native wire fixture; never an HTTP/provider service. */
[$script, $api, $dependencies, $phase] = $argv;
$loader = require $dependencies . '/vendor/autoload.php';
require $dependencies . '/.github/scripts/verify-local.php';
if ( ( $argv[4] ?? '' ) !== 'worker' ) {
    $runtime = sys_get_temp_dir() . '/support-wire-' . bin2hex( random_bytes( 8 ) );
    $environment = localEnvironment( $runtime );
    $input = stream_get_contents( STDIN );
    try {
        $process = new Symfony\Component\Process\Process( [ PHP_BINARY, __FILE__, $api, $dependencies, $phase, 'worker' ], $api, $environment );
        $process->setInput( $input ); $process->setTimeout( 120 ); $process->mustRun();
        try { $result = json_decode( $process->getOutput(), true, flags: JSON_THROW_ON_ERROR ); }
        catch ( JsonException $error ) { throw new RuntimeException( 'API worker failed: ' . $process->getOutput() . $process->getErrorOutput() ); }
    } finally { ( new Symfony\Component\Filesystem\Filesystem() )->remove( $runtime ); }
    if ( is_dir( $runtime ) ) { throw new RuntimeException( 'Owned API wire runtime cleanup failed.' ); }
    $result['owned_cleanup'] = true;
    echo json_encode( $result, JSON_THROW_ON_ERROR );
    exit;
}
$loader->setPsr4( 'App\\', [ $api . '/app' ] );
$loader->setPsr4( 'Database\\Factories\\', [ $api . '/database/factories' ] );
foreach ( $loader->getClassMap() as $class => $path ) {
    $path = realpath( $path ) ?: $path;
    if ( str_starts_with( $path, $dependencies . '/app/' ) || str_starts_with( $path, $dependencies . '/database/' ) ) {
        $loader->addClassMap( [ $class => $api . substr( $path, strlen( $dependencies ) ) ] );
    }
}
$app = require $api . '/bootstrap/app.php';
$app->make( Illuminate\Contracts\Console\Kernel::class )->bootstrap();
Illuminate\Support\Facades\Artisan::call( 'migrate:fresh', [ '--force' => true, '--no-interaction' => true ] );
require $dependencies . '/tests/Feature/CustomerSupport/SupportFixtures.php';
$c = supportContext();
$c['site']->update( [ 'url' => 'https://support-fixture.invalid' ] );
$c['setting']->update( [ 'content_ids' => [ 11 ] ] );
$request = Illuminate\Http\Request::create( '/api/v1/customer-support/settings', 'GET' );
$request->attributes->set( 'yoro_site', $c['site'] );
$settings = app( App\Http\Controllers\Api\V1\CustomerSupportSettingsController::class )->show( $request )->getData( true );
if ( ! str_starts_with( ( new ReflectionClass( App\Http\Controllers\Api\V1\CustomerSupportSettingsController::class ) )->getFileName(), $api . '/' ) ) { throw new RuntimeException( 'API source selection drift.' ); }
$result = [ 'settings_response' => $settings, 'settings_producer_file' => ( new ReflectionClass( App\Http\Controllers\Api\V1\CustomerSupportSettingsController::class ) )->getFileName() ];
if ( 'answer' === $phase ) {
    if ( ! str_starts_with( ( new ReflectionClass( App\Services\CustomerSupport\SupportFacts::class ) )->getFileName(), $api . '/' ) || ! str_starts_with( ( new ReflectionClass( App\Services\CustomerSupport\SupportTurns::class ) )->getFileName(), $api . '/' ) ) { throw new RuntimeException( 'API consumer source drift.' ); }
    $snapshot = json_decode( stream_get_contents( STDIN ), true, flags: JSON_THROW_ON_ERROR );
    $validated = app( App\Services\CustomerSupport\SupportFacts::class )->validate( $c['site'], $c['setting'], $snapshot, $c['session'] );
    if ( $validated !== $snapshot ) { throw new RuntimeException( 'Native public DTO changed under actual API validation.' ); }
    $rejected = [];
    $mutations = [ 'unknown-field' => $snapshot + [ 'untrusted' => true ], 'foreign-host' => $snapshot, 'unselected-source' => $snapshot ];
    $mutations['foreign-host']['sources'][0]['url'] = 'https://foreign.invalid/policy';
    $mutations['unselected-source']['sources'][0]['id'] = 'content:999999';
    foreach ( $mutations as $name => $mutation ) {
        try { app( App\Services\CustomerSupport\SupportFacts::class )->validate( $c['site'], $c['setting'], $mutation, $c['session'] ); }
        catch ( App\Services\CustomerSupport\SupportFailure ) { $rejected[] = $name; }
    }
    if ( count( $rejected ) !== 3 ) { throw new RuntimeException( 'Actual API wire validation accepted an adversarial DTO.' ); }
    Illuminate\Support\Facades\Http::fake( [ 'api.openai.com/*' => Illuminate\Support\Facades\Http::response( supportModel( [ 'answer' => 'Native wire fixture policy.', 'citations' => [ 'content:11' ] ] ) ) ] );
    $turns = app( App\Services\CustomerSupport\SupportTurns::class );
    $started = $turns->start( $c['site'], $c['key'], $c['session'], (string) Illuminate\Support\Str::uuid(), 'What is the policy?', $snapshot );
    if ( 'validate' !== $started['status'] ) { throw new RuntimeException( 'Actual API did not reach validation.' ); }
    $completed = $turns->advance( $c['site'], $c['key'], $c['session'], $started['turn_id'], $snapshot, 'complete' );
    if ( 'completed' !== $completed['status'] || 1 !== App\Models\CreditLedgerEntry::query()->where( 'entry_type', 'consume' )->count() ) { throw new RuntimeException( 'Actual API answer did not settle exactly once.' ); }
    $result += [ 'rejected_native_dto_mutations' => $rejected, 'validated_snapshot' => $validated, 'completed_response' => $completed, 'consumer_file' => ( new ReflectionClass( App\Services\CustomerSupport\SupportFacts::class ) )->getFileName(), 'fake_model_requests' => Illuminate\Support\Facades\Http::recorded()->count(), 'provider_requests' => 0, 'durable_consume_rows' => 1 ];
} elseif ( 'settings' !== $phase ) { throw new RuntimeException( 'Unknown wire phase.' ); }
$application_classes = [];
foreach ( get_declared_classes() as $class ) {
    if ( ! str_starts_with( $class, 'App\\' ) && ! str_starts_with( $class, 'Database\\Factories\\' ) ) { continue; }
    $file = ( new ReflectionClass( $class ) )->getFileName();
    if ( ! $file || ! str_starts_with( realpath( $file ), realpath( $api ) . '/' ) ) { throw new RuntimeException( 'Loaded application class escaped the selected API source: ' . $class ); }
    $application_classes[$class] = [ 'path' => realpath( $file ), 'sha256' => hash_file( 'sha256', $file ) ];
}
foreach ( $loader->getClassMap() as $class => $file ) {
    if ( str_starts_with( $class, 'App\\' ) && ! str_starts_with( $file, $api . '/' ) ) { throw new RuntimeException( 'Application classmap escaped the selected source.' ); }
}
if ( $loader->getPrefixesPsr4()['App\\'] !== [ $api . '/app' ] || realpath( $app->basePath() ) !== realpath( $api )
    || realpath( config_path() ) !== realpath( $api . '/config' ) || realpath( database_path() ) !== realpath( $api . '/database' ) ) { throw new RuntimeException( 'Selected API bootstrap/config/database/PSR4 root drift.' ); }
$result['loaded_application_classes'] = $application_classes;
$result['bootstrap_roots'] = [ 'base' => $app->basePath(), 'config' => config_path(), 'database' => database_path(), 'routes' => $api . '/routes' ];
Illuminate\Support\Facades\DB::disconnect();
echo json_encode( $result, JSON_THROW_ON_ERROR );
