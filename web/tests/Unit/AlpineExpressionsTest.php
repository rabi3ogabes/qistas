<?php

/*
 * The pages run Alpine's CSP build, whose expression parser has no template literals: a backtick in a directive throws
 * at run time and the binding silently does nothing (a form field without its name, for one). Build such strings in a
 * component method instead.
 */
it('keeps template literals out of Alpine directives, which the CSP build cannot read', function () {
    $offenders = [];
    $views = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }
        preg_match_all('/\s(?:x-[\w:.-]+|:[\w-]+|@[\w.-]+)="[^"]*`[^"]*"/', (string) file_get_contents($file->getPathname()), $matches);
        foreach ($matches[0] as $match) {
            $offenders[] = str_replace($views.DIRECTORY_SEPARATOR, '', $file->getPathname()).': '.trim($match);
        }
    }

    expect($offenders)->toBe([]);
});
