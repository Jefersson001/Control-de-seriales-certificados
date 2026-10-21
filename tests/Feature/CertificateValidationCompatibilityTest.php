<?php

use App\Actions\GetDashboardStatistics;
use App\CertificateStatus;
use App\Models\MsCertificado;
use App\Models\User;
use App\UserPermission;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('invalid record filters preserve validation rules on sqlite', function (array $invalidAttributes) {
    $validAttributes = [
        'no' => '123', 'fabricacion' => '2026', 'niv' => '1HGBH41JXMN109186',
    ];
    $valid = MsCertificado::factory()->create($validAttributes);
    $invalid = MsCertificado::factory()->create(array_replace($validAttributes, $invalidAttributes));

    expect(MsCertificado::query()->filterByNivStatus('invalid_records')->pluck('id')->all())
        ->toBe([$invalid->id])->not->toContain($valid->id);
})->with([
    'empty number' => [['no' => '']],
    'nonnumeric number' => [['no' => '12A']],
    'nonnumeric manufacturing year' => [['fabricacion' => '20A6']],
    'short manufacturing year' => [['fabricacion' => '026']],
    'empty brand' => [['marca' => '']],
    'empty model' => [['modelo' => '']],
    'empty type' => [['tipo' => '']],
    'out of range year' => [['anio' => 999]],
    'short niv' => [['niv' => '1234']],
    'forbidden niv character' => [['niv' => '1HGBI41JXMN109186']],
    'punctuation in niv' => [['niv' => '1HGB-41JXMN109186']],
    'missing control number' => [['codigo' => '']],
]);

test('mysql validation uses bound regular expressions instead of sqlite glob syntax', function () {
    $query = MsCertificado::on('mysql')->filterByNivStatus('invalid_records');

    expect($query->toSql())->not->toContain('GLOB')
        ->toContain('`no` REGEXP ?', '`fabricacion` REGEXP ?', 'UPPER(niv) REGEXP ?')
        ->and($query->getBindings())->toContain('[^0-9]', '[^A-HJ-NPR-Z0-9]');
});

test('dashboard counts duplicate records and unique niv using one grouped aggregate', function () {
    $user = User::factory()->create(['permissions' => [UserPermission::ViewCertificates->value]]);
    $validAttributes = ['no' => '123', 'fabricacion' => '2026', 'niv' => '1HGBH41JXMN109186'];
    MsCertificado::factory()->count(3)->create($validAttributes);
    MsCertificado::factory()->create(array_replace($validAttributes, [
        'niv' => '1HGBH41JXMN109187', 'status' => CertificateStatus::Dispatched,
    ]));
    MsCertificado::factory()->create(array_replace($validAttributes, ['niv' => '123']));

    DB::enableQueryLog();
    $statistics = app(GetDashboardStatistics::class)->handle($user);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($statistics['totals']['serials'])->toBe(5)
        ->and($statistics['master']['duplicates'])->toBe(3)
        ->and($statistics['master']['unique_niv'])->toBe(2)
        ->and($statistics['master']['invalid_records'])->toBe(1)
        ->and($statistics['master']['invalid_niv_length'])->toBe(1)
        ->and($statistics['master']['dispatched'])->toBe(1)
        ->and(collect($queries)->where(fn (array $query): bool => str_contains($query['query'], 'niv_counts'))->count())->toBe(1)
        ->and(collect($queries)->where(fn (array $query): bool => str_contains($query['query'], 'having'))->count())->toBe(0);

    Livewire::actingAs($user)->test('dashboard-overview')
        ->assertSet('statistics.totals.serials', 5)
        ->assertSet('statistics.master.invalid_records', 1)
        ->assertSet('statistics.master.dispatched', 1)
        ->assertDontSeeHtml('<select');
});

test('dashboard grouped niv totals are zero when the master is empty', function () {
    $user = User::factory()->create(['permissions' => [UserPermission::ViewCertificates->value]]);
    $statistics = app(GetDashboardStatistics::class)->handle($user);

    expect($statistics['master']['duplicates'])->toBe(0)
        ->and($statistics['master']['unique_niv'])->toBe(0);
});
