<?php

use App\Services\LogoOptimizer;
use Illuminate\Http\UploadedFile;

function makePngUpload(int $width, int $height): UploadedFile
{
    $img = imagecreatetruecolor($width, $height);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
    imagefilledrectangle($img, 10, 10, $width - 10, $height - 10, imagecolorallocate($img, 91, 63, 230));
    $path = tempnam(sys_get_temp_dir(), 'logo') . '.png';
    imagepng($img, $path);

    return new UploadedFile($path, 'logo.png', 'image/png', null, true);
}

it('réduit un grand logo à 512 px en WebP en gardant la transparence', function () {
    $webp = LogoOptimizer::toWebp(makePngUpload(2000, 1000));

    expect($webp)->not->toBeNull();
    $img = imagecreatefromstring($webp);
    expect(imagesx($img))->toBe(512)
        ->and(imagesy($img))->toBe(256)
        ->and((imagecolorat($img, 0, 0) >> 24) & 0x7F)->toBeGreaterThan(100); // coin transparent
});

it('ne grossit pas un petit logo', function () {
    $img = imagecreatefromstring(LogoOptimizer::toWebp(makePngUpload(200, 100)));

    expect(imagesx($img))->toBe(200)->and(imagesy($img))->toBe(100);
});

it('renvoie null pour un fichier qui n\'est pas une image', function () {
    $path = tempnam(sys_get_temp_dir(), 'txt');
    file_put_contents($path, 'pas une image');

    expect(LogoOptimizer::toWebp(new UploadedFile($path, 'logo.png', 'image/png', null, true)))->toBeNull();
});
