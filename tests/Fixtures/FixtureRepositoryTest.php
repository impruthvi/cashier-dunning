<?php

use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;
use Impruthvi\CashierDunning\Fixtures\FixtureRepository;

it('finds the reference corpus that ships with the package', function () {
    expect((new FixtureRepository)->scenarios())
        ->toHaveKey('trial-dunning-cancel-reactivate');
});

it('loads a fixture by scenario name', function () {
    expect((new FixtureRepository)->find('trial-dunning-cancel-reactivate')->steps)
        ->toHaveCount(7);
});

it('lists the available scenarios when asked for one that does not exist', function () {
    // The name is usually a typo, and the answer is on screen already.
    (new FixtureRepository)->find('nope');
})->throws(InvalidFixture::class, 'trial-dunning-cancel-reactivate');

it('lets an application recording shadow a shipped one of the same name', function () {
    // Dunning behaviour depends on Stripe account settings — retry counts,
    // what happens after the last retry. Yours are not the corpus author's, so
    // re-recording a shipped scenario has to replace it, not sit beside it.
    $application = sys_get_temp_dir().'/cashier-dunning-test-'.uniqid().'/stripe';
    mkdir($application, 0755, true);
    file_put_contents($application.'/trial-dunning-cancel-reactivate.json', '{}');

    $repository = new FixtureRepository(dirname($application));

    expect($repository->scenarios()['trial-dunning-cancel-reactivate'])
        ->toStartWith(dirname($application));

    unlink($application.'/trial-dunning-cancel-reactivate.json');
    rmdir($application);
    rmdir(dirname($application));
});

it('does not mistake a quarantined recording for a fixture', function (string $name) {
    // A failed recording is kept for diagnosis beside the fixtures, not as
    // one. Listing it as a scenario offers a replay of something that never
    // completed, and the doctor would report it as a broken fixture. Only
    // provider folders are fixtures, so this holds whatever the file is called.
    $root = sys_get_temp_dir().'/cashier-dunning-test-'.uniqid();
    mkdir($root.'/quarantine', 0755, true);
    file_put_contents($root.'/quarantine/'.$name, '{}');

    $repository = new FixtureRepository($root);

    try {
        expect($repository->files())->not->toContain(nativePath($root, 'quarantine', $name))
            ->and($repository->scenarios())->not->toHaveKey(basename($name, '.json'));
    } finally {
        unlink($root.'/quarantine/'.$name);
        rmdir($root.'/quarantine');
        rmdir($root);
    }
})->with([
    'quarantine suffix' => 'trial-dunning-cancel-reactivate-20260101-000000.quarantine.json',
    'plain json' => 'abandoned-recording.json',
]);

it('ignores an application path that does not exist', function () {
    expect((new FixtureRepository('/no/such/directory'))->paths())->toHaveCount(1);
});
