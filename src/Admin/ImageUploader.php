<?php

namespace App\Admin;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Enregistre les images envoyées depuis l'administration dans public/uploads/
 * et renvoie le chemin à stocker en base (ex. « uploads/pilotes/6650c1a2e4f3b.jpg »),
 * au même format que les données existantes.
 */
final class ImageUploader
{
    private const UPLOAD_DIR = 'uploads';

    public function __construct(
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDir,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function upload(UploadedFile $file, string $subDirectory): string
    {
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg');
        $fileName = bin2hex(random_bytes(8)) . '.' . $extension;
        $relativeDir = self::UPLOAD_DIR . '/' . trim($subDirectory, '/');

        $file->move($this->publicDir . '/' . $relativeDir, $fileName);

        return $relativeDir . '/' . $fileName;
    }

    /**
     * Supprime une image précédemment envoyée. Ne touche jamais à un fichier
     * situé en dehors de public/uploads/ (images du thème, liens externes…).
     */
    public function remove(?string $relativePath): void
    {
        if (!$relativePath || !str_starts_with($relativePath, self::UPLOAD_DIR . '/')) {
            return;
        }

        $uploadRoot = realpath($this->publicDir . '/' . self::UPLOAD_DIR);
        $path = realpath($this->publicDir . '/' . $relativePath);

        if ($uploadRoot && $path && str_starts_with($path, $uploadRoot . DIRECTORY_SEPARATOR) && is_file($path)) {
            $this->filesystem->remove($path);
        }
    }
}
