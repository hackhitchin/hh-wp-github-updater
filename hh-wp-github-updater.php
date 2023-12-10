<?php

/**
 * Plugin Name: GitHub Updater
 * Description: Fetch updates to plugins and themes (with appropriate Update URIs) from public GitHub repositories.
 * Version: 0.2.1
 * Author: Mark Thompson
 * Update URI: https://github.com/hackhitchin/hh-wp-github-updater
 */

namespace HitchinHackspace\GitHubUpdater;

use Throwable;

const SCHEME = 'github';

function get_http_status($url) {
    static $context;

    try {
        // Only perform HEAD requests...
        if (!$context) {
            $context = stream_context_create([
                'http' => ['method' => 'HEAD']
            ]);
        }
        
        // Get the response headers for this file.
        $headers = get_headers($url, true, $context);

        // Not a valid HTTP response?
        if (!is_array($headers))
            return false;

        // Filter out everything except the status ones:
        $status = array_values(array_filter($headers, function($index) { return is_int($index); }, ARRAY_FILTER_USE_KEY));

        // We've got at least *one* status code, right?
        if (!$status)
            return false;
        
        // Get the final one.
        $status = array_pop($status);

        // Extract the status code from it
        $code = explode(' ', $status)[1];

        return $code;
    }
    catch (Throwable $t) {
        // Ignored.
    }

    return null;
}

function url_exists($url) {
    return get_http_status($url) == 200;
}

function get_raw_uri($repoURI, $filename) {
    // Get the location of machine-readable files.
    $rawURI = str_replace('https://github.com', 'https://raw.githubusercontent.com', $repoURI) . '/master';

    // ... specifically, the file with the data we need
    return "$rawURI/$filename";
}

function get_update_package_uri($repoURI) {
    // Get the location of machine-readable files.
    // We'll invent a new github:// scheme so we can detect (later on) if it's a package we're in charge of.
    return str_replace('https://github.com/', SCHEME . '://', $repoURI);
}

// Fetch update information from GitHub, given a repository and a file within it containing metadata.
function get_github_file_data($repoURI, $filename, $keys) {
    $infoURI = get_raw_uri($repoURI, $filename);

    error_log("Fetching update information from: $infoURI");

    try {
        $data = get_file_data($infoURI, $keys);

        if ($data) {
            // Tell WordPress where to get the archive, if it wants.
            $data['package'] = get_update_package_uri($repoURI);

            error_log("Got update information: " . print_r($data, true));
        }
        else
            error_log("Unable to fetch update information.");
            
        return $data;
    }
    catch (Throwable $t) {
        error_log("Unable to fetch update information: " . $t->getMessage());
        // Fall through
    }

    return null;
}

// Fetch automatic updates from github
add_filter('update_plugins_github.com', function($update, $plugin_data, $plugin_file, $locales) {
    $repoURI = $plugin_data['UpdateURI'];

    $githubInfo = get_github_file_data($repoURI, basename($plugin_file), [
        'id' => 'Update URI',
        'version' => 'Version',
        'url' => 'Plugin URI'
    ]);

    if (!$githubInfo)
        return $update;

    // Is there an icon of some kind?
    $iconURI = get_raw_uri($repoURI, 'icon.svg');

    if (url_exists($iconURI)) {
        $githubInfo['icons'] = [
            'svg' => $iconURI
        ];
    }

    return $githubInfo;
 }, 10, 4);

 // Fetch automatic updates from github
add_filter('update_themes_github.com', function($update, $theme_data, $theme_stylesheet, $locales) {
    $repoURI = $theme_data['UpdateURI'];

    $githubInfo = get_github_file_data($repoURI, 'style.css', [
        'theme' => 'Theme Name',
        'url' => 'Theme URI',
        'version' => 'Version',
    ]);

    return $githubInfo ?: $update;
}, 10, 4);

add_filter('upgrader_source_selection', function($source, $remote, $upgrader, $extra) {
    $updaterExtra = $extra['hh-wp-github-updater'] ?? [];
    if (!$updaterExtra)
        return $source;

    $packageURI = $updaterExtra['source'] ?? '';
    if (!$packageURI)
        return $source;

    $upgrader->skin->feedback('Handling GitHub update for: %s', $packageURI);

    // Ignore whatever name was in the archive...
    $updateSource = substr($source, 0, strrpos($source, '/', -2));
    
    // ... and replace with the plain name of the repository.
    $updateSource .= substr($packageURI, strrpos($packageURI, '/'));

    $updateSource = trailingslashit($updateSource);

    if ($source != $updateSource) {
        $upgrader->skin->feedback('Renaming: %s -> %s', $source, $updateSource);
        move_dir($source, $updateSource);
    }

    return $updateSource;
}, 10, 4);

add_filter('upgrader_package_options', function($options) {
    $packageURI = $options['package'];
    $packageScheme = parse_url($packageURI, PHP_URL_SCHEME);
    
    if ($packageScheme == SCHEME) {
        $options['package'] = str_replace(SCHEME . '://', 'https://github.com/', $packageURI) . '/archive/refs/heads/master.zip';
        if (!array_key_exists('hook_extra', $options))
            $options['hook_extra'] = [];
        $options['hook_extra']['hh-wp-github-updater'] = [
            'source' => $packageURI
        ];
    }

    return $options;
});