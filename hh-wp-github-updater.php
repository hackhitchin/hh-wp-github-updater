<?php

/**
 * Plugin Name: GitHub Updater
 * Description: Fetch updates to plugins and themes (with appropriate Update URIs) from public GitHub repositories.
 * Version: 0.1
 * Author: Mark Thompson
 * Update URI: https://github.com/hackhitchin/hh-wp-github-updater
 */


namespace HitchinHackspace\GitHubUpdater;

use Throwable;

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

// Fetch update information from GitHub, given a repository and a file within it containing metadata.
function get_github_file_data($repoURI, $filename, $keys) {
    $infoURI = get_raw_uri($repoURI, $filename);

    error_log("Fetching update information from: $infoURI");

    try {
        $data = get_file_data($infoURI, $keys);

        if ($data) {
            // Tell WordPress where to get the archive, if it wants.
            $data['package'] = "$repoURI/archive/refs/heads/master.zip";

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