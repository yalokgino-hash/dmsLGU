<?php
/**
 * MongoDB connection config.
 * Set MONGODB_URI in environment, or replace the default below.
 * Atlas: mongodb+srv://USER:PASSWORD@cluster0.jxcbcd5.mongodb.net/
 * Local: mongodb://localhost:27017
 */
return [
    'uri'        => getenv('MONGODB_URI') ?: 'mongodb+srv://ojt_db_user:dkWe9yQ9jJIi3neW@cluster0.jxcbcd5.mongodb.net/',
    'database'   => getenv('MONGODB_DB') ?: 'dmsLGU',
    'collection' => getenv('MONGODB_COLLECTION') ?: 'offices',
];
