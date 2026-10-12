<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\Visibility;

/**
 * A disk kept in a database table (Win Plan D5), for a host whose own disk does not last (Vercel's is thrown away).
 * It is storage, like a bucket: one row per path, the bytes as base64 text so every database keeps them alike, and
 * everything private. Files is still the only writer; moving to a bucket later is a change of disk with the same paths.
 */
final class DatabaseFilesystem implements FilesystemAdapter
{
    public function __construct(private readonly string $table = 'file_blobs', private readonly ?string $connection = null) {}

    public function fileExists(string $path): bool
    {
        return $this->rows()->where('path', $path)->exists();
    }

    public function directoryExists(string $path): bool
    {
        return $this->under($this->prefix($path))->exists();
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $now = now();
        $this->rows()->upsert(
            [['path' => $path, 'contents' => base64_encode($contents), 'size' => strlen($contents), 'mime' => $this->sniff($contents), 'created_at' => $now, 'updated_at' => $now]],
            ['path'],
            ['contents', 'size', 'mime', 'updated_at'],
        );
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->write($path, (string) stream_get_contents($contents), $config);
    }

    public function read(string $path): string
    {
        $contents = $this->rows()->where('path', $path)->value('contents');

        if (! is_string($contents)) {
            throw UnableToReadFile::fromLocation($path, 'There is no such file.');
        }

        return (string) base64_decode($contents, true);
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $this->read($path));
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        $this->rows()->where('path', $path)->delete();
    }

    public function deleteDirectory(string $path): void
    {
        $this->under($this->prefix($path))->delete();
    }

    public function createDirectory(string $path, Config $config): void
    {
        // Folders are only parts of paths here, as in a bucket.
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // Every file is private; a link that expires is the only way out.
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, Visibility::PRIVATE);
    }

    public function mimeType(string $path): FileAttributes
    {
        $mime = $this->rows()->where('path', $path)->value('mime');

        return is_string($mime) ? new FileAttributes($path, null, null, null, $mime) : throw UnableToRetrieveMetadata::mimeType($path, 'There is no such file.');
    }

    public function lastModified(string $path): FileAttributes
    {
        $updated = $this->rows()->where('path', $path)->value('updated_at');

        return $updated !== null ? new FileAttributes($path, null, null, strtotime((string) $updated) ?: null) : throw UnableToRetrieveMetadata::lastModified($path, 'There is no such file.');
    }

    public function fileSize(string $path): FileAttributes
    {
        $size = $this->rows()->where('path', $path)->value('size');

        return $size !== null ? new FileAttributes($path, (int) $size) : throw UnableToRetrieveMetadata::fileSize($path, 'There is no such file.');
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $prefix = $this->prefix($path);
        $folders = [];

        foreach ($this->under($prefix)->orderBy('path')->get(['path', 'size', 'updated_at', 'mime']) as $row) {
            $rest = substr((string) $row->path, strlen($prefix));
            $slash = strpos($rest, '/');

            if (! $deep && $slash !== false) {
                $folder = $prefix.substr($rest, 0, $slash);
                if (! isset($folders[$folder])) {
                    $folders[$folder] = true;
                    yield new DirectoryAttributes($folder);
                }

                continue;
            }

            yield new FileAttributes((string) $row->path, (int) $row->size, Visibility::PRIVATE, strtotime((string) $row->updated_at) ?: null, (string) $row->mime);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->copy($source, $destination, $config);
        $this->delete($source);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->write($destination, $this->read($source), $config);
    }

    private function rows(): Builder
    {
        /** @var ConnectionInterface $connection */
        $connection = DB::connection($this->connection);

        return $connection->table($this->table);
    }

    /** "a/b" and "a/b/" both mean everything inside a/b; "" means everything. */
    private function prefix(string $path): string
    {
        $path = trim($path, '/');

        return $path === '' ? '' : $path.'/';
    }

    /** The rows whose path starts with [$prefix], compared exactly (LIKE would read the `_` in `id_front` as a wildcard). */
    private function under(string $prefix): Builder
    {
        return $prefix === '' ? $this->rows() : $this->rows()->whereRaw('substr(path, 1, ?) = ?', [strlen($prefix), $prefix]);
    }

    private function sniff(string $contents): string
    {
        return $contents === '' ? 'application/x-empty' : (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
    }
}
