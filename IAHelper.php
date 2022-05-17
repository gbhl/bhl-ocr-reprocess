<?php
require_once 'CacheHelper.php';

class IAHelper extends CacheHelper {
    static function get($identifier, $useCache = TRUE) {
        if ($useCache && ($metadata = parent::search($identifier, IA_CACHE_PATH, 'json'))) {
            return $metadata;
        }

        $url = "https://archive.org/metadata/{$identifier}/metadata";
        if ($result = file_get_contents($url)) {
            $result = json_decode($result);

            if (isset($result->error)) {
                return FALSE;
            }

            $result = json_encode($result->result, JSON_PRETTY_PRINT);
            parent::save($identifier, IA_CACHE_PATH, 'json', $result);
            return $result;
        }
    }

    static function query($query, $fields, $output = 'json') {
        if (!is_array($query)) {
            throw new Exception('First parameter must be an array.');
        }

        $query = [
            'q' => implode(' AND ', $query),
            'fl' => $fields,
            'rows' => IA_NUM_OF_ROWS,
            'output' => $output,
            'sort' => 'identifier'
        ];

        $url = implode('?', ['https://archive.org/advancedsearch.php', http_build_query($query)]);
        $results = file_get_contents($url);
        if ($json = json_decode($results)) {
            if ($json->response->numFound > 0) {
                return $json->response->docs;
            } else {
                return NULL;
            }
        } else {
            throw new Exception('Error decoding JSON.');
        }
    }
}
