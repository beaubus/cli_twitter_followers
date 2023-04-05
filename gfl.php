<?php

include 'vendor/autoload.php';

use Abraham\TwitterOAuth\TwitterOAuth;
use Dotenv\Dotenv;

const PAGINATION_TOKENS_FILE = 'pagination_tokens.csv';

// read username from params
if (empty($argv[1])) die("Please enter username\n");

// Get username from first argument
$name = str_replace('@', '', $argv[1]);
echo "getting followers of user $name ... \n";


// load environmental variables
$dotenv = Dotenv::createUnsafeImmutable(__DIR__);
$dotenv->load();

// set Twitter api connection
$connection = new TwitterOAuth(
    getenv('API_KEY'),
    getenv('API_KEY_SECRET'),
    getenv('ACCESS_TOKEN'),
    getenv('ACCESS_TOKEN_SECRET')
);
$connection->setApiVersion('2');

// get user id
$response = $connection->get("users/by/username/$name");
$id_str = $response->data->id ?? ''; // user_id
if (!$id_str) die('no such user, or wrong credentials for api connection');

// set Twitter api connection
$connection = new TwitterOAuth(
    getenv('API_KEY'),
    getenv('API_KEY_SECRET'),
    getenv('ACCESS_TOKEN'),
    getenv('ACCESS_TOKEN_SECRET')
);
$connection->setApiVersion('2');

outputFollowers($id_str, $connection);

/**
 * Recursively output followers into twitter_users_data table
 */
function outputFollowers(string $id_str, TwitterOAuth $connection, string $next_token = ''): void
{
    $options = [
        'max_results' => 1000,
        'user.fields' => 'name,description',
    ];

    if (!empty($next_token)) {
        $options['pagination_token'] = $next_token;
        echo PHP_EOL;
    } elseif ($next_token = getCachedNextToken($id_str)) {
        echo "resuming from cache ...\n";
        $options['pagination_token'] = $next_token;
        echo PHP_EOL;
    }

    $response = $connection->get("users/$id_str/followers", $options); // that gives 1000 results and next token

    // wait for 15 minutes on rate limit
    if (isset($response->status) && $response->status == 429) {
        echo "\nRate limit. Waiting 15 minutes...\n";
        sleep(900);
        outputFollowers($id_str, $connection, $next_token);
        return;
    }

    foreach ($response->data as $data) echo $data->name . PHP_EOL;

    // call for the next page till pagination ends
    if (!empty($response->meta->next_token)) {
        saveNextToken($id_str, $response->meta->next_token);
        outputFollowers($id_str, $connection, $response->meta->next_token);
    }

    removeNextTokens($id_str); // remove next tokens for user from db when we got all the followers
}

/**
 * Save API pagination next page token
 */
function saveNextToken(string $id_str, string $next_token): void
{
    removeNextTokens($id_str); // remove if we already have pagination for user

    // Save to file
    file_put_contents(PAGINATION_TOKENS_FILE, "$id_str,$next_token");
}

/**
 * Remove API pagination tokens for user
 */
function removeNextTokens(string $id_str): void
{
    if (!file_exists(PAGINATION_TOKENS_FILE)) return;

    $file = file(PAGINATION_TOKENS_FILE);
    $file = array_filter($file, fn($line) => !str_contains($line, "$id_str,"));
    file_put_contents(PAGINATION_TOKENS_FILE, $file);
}

function getCachedNextToken(string $id_str): ?string
{
    if (!file_exists(PAGINATION_TOKENS_FILE)) return null;

    $file = file(PAGINATION_TOKENS_FILE);
    $file = array_filter($file, fn($line) => str_contains($line, "$id_str,"));
    if (!count($file)) return null;

    $token = explode(',', $file[array_key_last($file)]);
    return $token[1];
}