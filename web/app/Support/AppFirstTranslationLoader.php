<?php

namespace App\Support;

use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;

/**
 * Loads our own sentences (lang/app/<locale>.json) after the package-managed framework translations, so ours win.
 *
 * Laravel merges extra JSON paths first and the main language folder last, so a sentence that both files hold was
 * always taken from the framework's wording: Spanish "Admin" became "Administrar" (to administer), Urdu "Cancel"
 * became a bare "منسوخ" (cancelled) and "Confirm" an unfinished phrase. Ours are written for this product.
 */
final class AppFirstTranslationLoader extends FileLoader
{
    /** @param  array<int, string>|string  $path */
    public function __construct(Filesystem $files, array|string $path, private readonly string $appFolder)
    {
        parent::__construct($files, $path);
    }

    /** The loader Laravel registered, rebuilt so that it keeps its folders but loads ours last. */
    public static function from(FileLoader $loader, string $appFolder): self
    {
        [$files, $paths] = Closure::bind(fn (): array => [$this->files, $this->paths], $loader, FileLoader::class)();

        return new self($files, $paths, $appFolder);
    }

    /** @return array<string, string> */
    protected function loadJsonPaths($locale)
    {
        $strings = parent::loadJsonPaths($locale);
        $file = "{$this->appFolder}/{$locale}.json";

        if (! $this->files->exists($file)) {
            return $strings;
        }

        return array_merge($strings, json_decode($this->files->get($file), true, flags: JSON_THROW_ON_ERROR));
    }
}
