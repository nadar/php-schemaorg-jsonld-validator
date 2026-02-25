<?php

include 'vendor/autoload.php';

use Nadar\Schema\JsonLdValidator;

// FOR TESTING PURPOSES WHILE DEVELOPING THE LIBRARY :-)

$jsonLdInput = <<<'JSON'
{
    "@context":"https://schema.org",
    "@type":"Course",
    "name":"Gleichgewicht"
} 
JSON;

$validator = new JsonLdValidator();
$result = $validator->validate($jsonLdInput);

var_dump($result->getErrors());