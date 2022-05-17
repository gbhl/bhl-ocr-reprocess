<?php

abstract class CacheHelper {
    static function search($identifier, $directory, $ext) {
        $filename = static::getFilename($directory, $identifier, $ext);

        // Only return the the file is younger than what's specified.
        if (file_exists($filename)) {
            if (time() - filemtime($filename) < CACHE_EXPIRATION_IN_SECONDS) {
                return file_get_contents($filename);
            }
        }
        return NULL;
    }

    static function save($identifier, $directory, $ext, $content) {
        $filename = static::getFilename($directory, $identifier, $ext);
        file_put_contents($filename, $content);
    }

    static private function getFilename($directory, $identifier, $ext) {
        if (substr($directory, -1) != '/') {
            $directory = "{$directory}/";
        }
        return "{$directory}{$identifier}.{$ext}";
    }
}