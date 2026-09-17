<?php
// modulus-sync
// update module/author items of AUFO's Omeka S instance from a CSV file using the REST API
// v0.4 - © 2026 Christian Morel (boregar) - Apache-2.0 license

// ---------------------------------------- validity check

$version = '0.4';
$switches = [];
$curSwitch = null;

// get the command line arguments
foreach ($argv as $arg) {
  if (substr($arg, 0, 1) === '-') {
    $curSwitch = substr($arg, 1);
    if ($curSwitch === 'h') {
      echo "usage: php modulus-sync.php -c <configFile> -d <csvData> -v\n";
      echo "options:\n";
      echo "  -c: set configuration file\n";
      echo "  -d: set path/URL to CSV data source file\n";
      echo "  -v: get script version\n";
      exit;
    }
    elseif ($curSwitch === 'v') {
      echo "modulus-sync v$version\n";
      exit;
    }
  } elseif ($curSwitch) {
    $switches[$curSwitch] = $arg;
    $curSwitch = null;
  }
}

// check arguments
$configFile = $switches['c'] ?? null;
$csvFile = $switches['d'] ?? null;
if (!$configFile || !$csvFile) {
  echo "invalid request\n";
  exit;
}

// ---------------------------------------- initialisation

// read the configuration file
$fileContent = file_get_contents($configFile);
if (!$fileContent) {
  echo "config file not found\n";
  exit;
}
$config = json_decode($fileContent, true);
if (!$config) {
  echo "invalid configuration\n";
  exit;
}

// global vars
$apiKeys = sprintf('key_identity=%s&key_credential=%s', $config['api_key_identity'], $config['api_key_credential']);
$apiEndpoint = $config['api_endpoint'];
$modules = [];
$authors = [];

// ---------------------------------------- function interop($url, $verb, $payload)
// ---------------------------------------- send a request to Omeka S' REST API
// ---------------------------------------- $url: URL of the endpoint to send the request to
// ---------------------------------------- $verb: action to perform (GET/POST/PATCH)
// ---------------------------------------- $payload: data to send
// ---------------------------------------- return: content of the API response

function interop($url, $verb, $payload = null) {

  // curl init
  $ch = curl_init();
  $headers = [
    "Content-Type: application/json",
    "Accept: application/json"
  ];

  // set options
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  // avoid "Error:SSL certificate problem: unable to get local issuer certificate"
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
  switch ($verb) {
    case 'POST':
      curl_setopt($ch, CURLOPT_POST, 1);
      break;

    case 'PATCH':
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
      break;

    default:
      break;
  }
  if ($payload) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
  }

  // send the request
  curl_setopt($ch, CURLOPT_URL, $url);
  $result = curl_exec($ch);
  if (curl_errno($ch)) {
    echo 'Error:' . curl_error($ch) . '\n';
    curl_close($ch);
    exit;
  }
  curl_close($ch);

  // return the response
  $content = json_decode($result, true);
  return($content);
}

// ---------------------------------------- function searchItem($templateId, $identifierTerm, $identifierValue)
// ---------------------------------------- search an un item using Omeka S' REST API
// ---------------------------------------- $templateId: Omeka id of the resource template
// ---------------------------------------- $identifierTerm: RDF term of the property used as unique identifier
// ---------------------------------------- $identifierValue: identifier value
// ---------------------------------------- return: content of the API response

function searchItem($templateId, $identifierTerm, $identifierValue) {
  global $apiEndpoint;

  // build the URL
  $searchUrl = sprintf('%s?resource_template_id=%s&property[0][property]=%s&property[0][type]=eq&property[0][text]=%s', $apiEndpoint, $templateId, $identifierTerm, urlencode($identifierValue));

  // return the response
  $content = interop($searchUrl, 'GET');
  return($content);
}

// ---------------------------------------- function createItem($payload)
// ---------------------------------------- create an item using Omeka S' REST API
// ---------------------------------------- $payload: item data
// ---------------------------------------- return: content of the API response

function createItem($payload) {
  global $apiEndpoint;
  global $apiKeys;

  // build the URL
  $createUrl = sprintf('%s?%s', $apiEndpoint, $apiKeys);

  //print_r(json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  // return the response
  $content = interop($createUrl, 'POST', $payload);
  return($content);
}

// ---------------------------------------- function updateItem($templateId, $payload)
// ---------------------------------------- update an item using Omeka S' REST API
// ---------------------------------------- $itemId: Omeka id of the item
// ---------------------------------------- $payload: item data
// ---------------------------------------- return: content of the API response

function updateItem($itemId, $payload) {
  global $apiEndpoint;
  global $apiKeys;

  // build the URL
  $updateUrl = sprintf('%s/%s?%s', $apiEndpoint, $itemId, $apiKeys);

  // return the response
  $content = interop($updateUrl, 'PATCH', $payload);
  return($content);
}

// ---------------------------------------- function createAuthor($authorIdentifier, $row)
// ---------------------------------------- create a new author item
// ---------------------------------------- $authorIdentifier: unique identifier of the author
// ---------------------------------------- $row: CSV data
// ---------------------------------------- return: Omeka id of the new author item

function createAuthor($authorIdentifier, $row) {
  global $config;
  global $apiEndpoint;
  global $authors;

  // get GIT account of the author
  $matches = [];
  preg_match('/^https:\/\/git(hub|lab)\.com\/([a-zA-Z0-9-_]+)\/([a-zA-Z0-9-]+)$/', $row[$config['author_identifier_column']], $matches);
  $authorGitUrl = sprintf('https://git%s.com/%s', $matches[1], $matches[2]);

  // feed metadata structure
  $payload = $config['author_payload'];
  $payload['dcterms:title'][0]['@value'] = $row['Author'];
  $payload['dcterms:identifier'][0]['@value'] = $authorIdentifier;
  $payload['foaf:accountServiceHomepage'][0]['@id'] = $authorGitUrl;

  // create item
  $content = createItem($payload);
  $authorId = $content['o:id'];
  $authors[$authorIdentifier]['o:id'] = $authorId;

  // return Omeka id
  return $authorId;
}

// ---------------------------------------- function createModule($moduleIdentifier, $row)
// ---------------------------------------- create a new module
// ---------------------------------------- $moduleIdentifier: unique identifier of the module
// ---------------------------------------- $row: CSV data
// ---------------------------------------- return: Omeka id of the new module item

function createModule($moduleIdentifier, $row) {
  global $config;
  global $apiEndpoint;
  global $authors;
  global $modules;
  $moduleId = null;
  $authorId = null;

  // get author identifier
  $matches = [];
  preg_match('/^https:\/\/git(hub|lab)\.com\/([a-zA-Z0-9-_]+)\/([a-zA-Z0-9-]+)$/', $row[$config['author_identifier_column']], $matches);
  $authorIdentifier = $matches[2];

  // case where the author has already been searched (author of a module previously processed)
  if (isset($authors[$authorIdentifier]['o:id'])) {
    $authorId = $authors[$authorIdentifier]['o:id'];
    echo "-- using author \"$authorIdentifier\" with id $authorId\n";
  }
  // else search for the author in Omeka
  else {
    $authors[$authorIdentifier] = ['o:id' => null];
    $searchResult = searchItem($config['author_template_id'], $config['author_identifier_term'], $authorIdentifier);
    $searchCount = $searchResult ? count($searchResult) : null;
    // if the author was not found, create the author
    if (!$searchCount) {
      echo "-- creating new author \"$authorIdentifier\"\n";
      $authorId = createAuthor($authorIdentifier, $row);
      echo "-- created new author \"$authorIdentifier\" with id $authorId\n";

    }
    // if the author was found, check for unicity
    elseif ($searchCount === 1) {
      $authorId = $searchResult[0]['o:id'];
      $authors[$authorIdentifier]['o:id'] = $authorId;
      echo "-- using author \"$authorIdentifier\" with id $authorId\n";
    }
    // if the author has multiple occurences, choose the first
    else {
      echo "-- warning: multiple match for author \"$authorIdentifier\" ($searchCount items found)\n";
      $authorId = $searchResult[0]['o:id'];
      $authors[$authorIdentifier]['o:id'] = $authorId;
      echo "-- using author \"$authorIdentifier\" with id $authorId\n";
    }
  }

  // feed metadata structure
  $payload = $config['module_payload'];
  $payload['dcterms:title'][0]['@value'] = $row['Name'];
  $payload['dcterms:identifier'][0]['@value'] = $moduleIdentifier;
  $payload['schema:softwareVersion'][0]['@value'] = $row['Last version'];
  $payload['schema:releaseDate'][0]['@value'] = $row['Last update'] ? sprintf('%s+00:00', substr($row['Last update'], 0, 19)) : '1970-01-01';
  $payload['schema:creditText'][0]['@value'] = $row['Author'];
  $payload['schema:author'][0]['@id'] = "$apiEndpoint/$authorId";
  $payload['schema:author'][0]['value_resource_id'] = $authorId;
  $payload['schema:author'][0]['display_title'] = $authorIdentifier;
  $payload['dcterms:description'][0]['@value'] = $row['Description'];
  $payload['schema:targetPlatform'][0]['@value'] = $row['Omeka constraint'];
  $payload['schema:downloadUrl'][0]['@id'] = $row['Last released zip'];
  $payload['schema:license'][0]['@value'] = $row['License'];
  $payload['schema:newsUpdatesAndGuidelines'][0]['@id'] = $row['Url'];

  // create item
  $content = createItem($payload);
  $moduleId = $content['o:id'];
  $modules[$moduleIdentifier]['o:id'] = $moduleId;
  $modules[$moduleIdentifier]['payload'] = $content;

  // return Omeka id
  return $moduleId;
}

// ---------------------------------------- function updateModule($moduleIdentifier, $row)
// ---------------------------------------- update an existing module
// ---------------------------------------- $moduleIdentifier: unique identifier of the module
// ---------------------------------------- $row: CSV data
// ---------------------------------------- return: Omeka id of the module item

function updateModule($moduleIdentifier, $row) {
  global $config;
  global $modules;
  $moduleId = $modules[$moduleIdentifier]['o:id'];

  // feed metadata structure
  $payload = $modules[$moduleIdentifier]['payload'];
  $payload['schema:softwareVersion'][0]['@value'] = $row['Last version'];
  $payload['schema:releaseDate'][0]['type'] = 'numeric:timestamp';
  $payload['schema:releaseDate'][0]['property_id'] = 1515;
  $payload['schema:releaseDate'][0]['property_label'] = 'releaseDate';
  $payload['schema:releaseDate'][0]['is_public'] = true;
  $payload['schema:releaseDate'][0]['@value'] = $row['Last update'] ? sprintf('%s+00:00', substr($row['Last update'], 0, 19)) : '1970-01-01';

  // update item
  $content = updateItem($moduleId, $payload);
  $moduleId = $content['o:id'];

  // return Omeka id
  return $moduleId;
}

// ---------------------------------------- main
// ---------------------------------------- process the CSV data

echo "\nstart processing data from $csvFile\n";

if (($handle = fopen($csvFile, 'r')) !== false) {
  // first line contains column headers
  $headers = fgetcsv($handle, null, ',');
  $iRow = 0;

  // for each line, get module and author
  while (($data = fgetcsv($handle, null, ',')) !== false) {
    $iRow++;
    echo "\nreading line $iRow\n";
    $row = array_combine($headers, $data);
    $moduleIdentifier = $row[$config['module_identifier_column']];

    // case where the module has already been processed (the list contains duplicates): use the date of last update to decide whether or not to process it again
    if (isset($modules[$moduleIdentifier])) {
      echo "-- module \"$moduleIdentifier\" already processed\n";
      // compare the dates
      $origin = new DateTimeImmutable($modules[$moduleIdentifier]['lastUpdate']);
      $target = new DateTimeImmutable($row[$config['module_last_update_column']]);
      // continue if the date is earlier
      if ($target < $origin) {
        echo "-- last update is earlier\n";
      }
      // process if the date is more recent
      else {
        echo "-- last update is more recent\n";
        $modules[$moduleIdentifier]['lastUpdate'] = $row[$config['module_last_update_column']];
        $moduleId = $modules[$moduleIdentifier]['o:id'];
        echo "-- re-updating module \"$moduleIdentifier\" with id $moduleId\n";
        updateModule($moduleIdentifier, $row);
        echo "-- re-updated module \"$moduleIdentifier\" with id $moduleId\n";
      }
    }

    // regular case
    else {
      echo "-- processing module \"$moduleIdentifier\"\n";
      $modules[$moduleIdentifier] = ['o:id' => null, 'lastUpdate' => $row[$config['module_last_update_column']]];
      // search for the module in Omeka
      $searchResult = searchItem($config['module_template_id'], $config['module_identifier_term'], $moduleIdentifier);
      $searchCount = $searchResult ? count($searchResult) : null;
      // if the module was not found, create the module
      if (!$searchCount) {
        echo "-- creating new module \"$moduleIdentifier\"\n";
        $moduleId = createModule($moduleIdentifier, $row);
        echo "-- created new module \"$moduleIdentifier\" with id $moduleId\n";
      }
      // if the module was found, check for unicity
      elseif ($searchCount === 1) {
        $moduleId = $searchResult[0]['o:id'];
        $modules[$moduleIdentifier]['o:id'] = $moduleId;
        $modules[$moduleIdentifier]['payload'] = $searchResult[0];
        echo "-- updating module \"$moduleIdentifier\" with id $moduleId\n";
        updateModule($moduleIdentifier, $row);
        echo "-- updated module \"$moduleIdentifier\" with id $moduleId\n";
      }
      // if the module has multiple occurences, do not process
      else {
        echo "-- error: multiple match for module \"moduleIdentifier\" ($searchCount items)\n";
      }
    }

  }
}

echo "\nend processing data from $csvFile\n";
