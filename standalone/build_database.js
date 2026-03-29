#!/usr/bin/env node
/**
 * Build script: generates the SQLite database file for the static site.
 * Run once, commit the output db/browser.db, then no server is needed.
 *
 * Requires: npm install better-sqlite3
 * Usage:    node build_database.js [number_of_accessions]
 * Default:  20000 accessions
 */

const Database = require('better-sqlite3');
const path = require('path');
const fs = require('fs');
const crypto = require('crypto');

const numAccessions = parseInt(process.argv[2]) || 20000;
const dbPath = path.join(__dirname, 'db', 'browser.db');

if (fs.existsSync(dbPath)) {
    fs.unlinkSync(dbPath);
    console.log('Removed existing database.');
}

console.log('Creating database at: ' + dbPath);
console.log('Generating ' + numAccessions + ' accessions...');

const db = new Database(dbPath);
db.pragma('journal_mode = DELETE');

// -- Create tables (matching MySQL schema from Database.php) --
db.exec(
    'CREATE TABLE categories (' +
    '  id INTEGER PRIMARY KEY AUTOINCREMENT,' +
    '  priority INTEGER NOT NULL,' +
    '  category VARCHAR(256) NOT NULL,' +
    '  subcategory VARCHAR(256) NOT NULL,' +
    '  comment VARCHAR(1024)' +
    ');' +
    'CREATE INDEX categoryIndex ON categories(category);' +
    'CREATE INDEX subcategoryIndex ON categories(subcategory);' +
    'CREATE TABLE properties (' +
    '  id INTEGER PRIMARY KEY AUTOINCREMENT,' +
    '  image VARCHAR(32) NOT NULL,' +
    '  street_address VARCHAR(255) NOT NULL,' +
    '  photographer VARCHAR(255),' +
    '  date VARCHAR(255)' +
    ');' +
    'CREATE TABLE filters (' +
    '  id INTEGER PRIMARY KEY AUTOINCREMENT,' +
    '  fk_properties_id INTEGER NOT NULL,' +
    '  fk_categories_id INTEGER NOT NULL,' +
    '  FOREIGN KEY (fk_properties_id) REFERENCES properties(id),' +
    '  FOREIGN KEY (fk_categories_id) REFERENCES categories(id)' +
    ');' +
    'CREATE INDEX fk_properties_idIndex ON filters(fk_properties_id);' +
    'CREATE INDEX fk_categories_idIndex ON filters(fk_categories_id);' +
    'CREATE TABLE attributes (' +
    '  id INTEGER PRIMARY KEY AUTOINCREMENT,' +
    '  fk_properties_id INTEGER NOT NULL,' +
    '  name VARCHAR(256) NOT NULL,' +
    '  value VARCHAR(1024) NOT NULL,' +
    '  FOREIGN KEY (fk_properties_id) REFERENCES properties(id)' +
    ');' +
    'CREATE INDEX attr_fk_properties_idIndex ON attributes(fk_properties_id);'
);
console.log('Tables created.');

// -- Populate categories (4 categories x 10 subcategories = 40 rows) --
var categories = ['Creator', 'Style Period', 'Work Type', 'Region'];
var insertCat = db.prepare('INSERT INTO categories (priority, category, subcategory) VALUES (?, ?, ?)');
var populateCats = db.transaction(function () {
    var priority = 1;
    for (var c = 0; c < categories.length; c++) {
        for (var i = 1; i <= 10; i++) {
            insertCat.run(priority, categories[c], categories[c].charAt(0) + i);
        }
        priority++;
    }
});
populateCats();
console.log('Categories populated (40 rows).');

// -- Populate properties --
var years = [];
for (var y = 1900; y <= 2015; y++) years.push(y);
var directions = ['north', 'north east', 'east', 'south east', 'south', 'south west', 'west', 'north west'];
var prefixes = ['Mr.', 'Ms.', 'Mrs.', 'Miss', 'Dr.'];

function rand(arr) { return arr[Math.floor(Math.random() * arr.length)]; }

var insertProp = db.prepare('INSERT INTO properties (image, street_address, photographer, date) VALUES (?, ?, ?, ?)');
var BATCH = 5000;
var insertPropBatch = db.transaction(function (start, end) {
    for (var i = start; i <= end; i++) {
        insertProp.run(
            crypto.createHash('md5').update(crypto.randomUUID()).digest('hex'),
            (Math.floor(Math.random() * 1000) + 1) + ' ' + rand(directions),
            rand(prefixes) + ' ' + String.fromCharCode(64 + Math.floor(Math.random() * 27)),
            String(rand(years))
        );
    }
});
for (var s = 1; s <= numAccessions; s += BATCH) {
    var e = Math.min(s + BATCH - 1, numAccessions);
    insertPropBatch(s, e);
    process.stdout.write('\rProperties: ' + e + '/' + numAccessions);
}
console.log(' - done.');

// -- Populate filters (1-5 random category mappings per accession) --
var insertFilt = db.prepare('INSERT INTO filters (fk_properties_id, fk_categories_id) VALUES (?, ?)');
var insertFiltBatch = db.transaction(function (start, end) {
    for (var a = start; a <= end; a++) {
        var n = Math.floor(Math.random() * 5) + 1;
        for (var f = 0; f < n; f++) {
            insertFilt.run(a, Math.floor(Math.random() * 40) + 1);
        }
    }
});
for (var s = 1; s <= numAccessions; s += BATCH) {
    var e = Math.min(s + BATCH - 1, numAccessions);
    insertFiltBatch(s, e);
    process.stdout.write('\rFilters: ' + e + '/' + numAccessions);
}
console.log(' - done.');

// -- Populate attributes (3 per accession: Color, Clouds, Humidity) --
var colors = ['red', 'orange', 'yellow', 'green', 'blue', 'indigo', 'violet', 'Burnt Sienna'];
var clouds = ['Clear', 'Scattered/Partly Cloudy', 'Broken/Mostly Cloudy', 'Overcast', 'Obscured'];
var insertAttr = db.prepare('INSERT INTO attributes (fk_properties_id, name, value) VALUES (?, ?, ?)');
var insertAttrBatch = db.transaction(function (start, end) {
    for (var a = start; a <= end; a++) {
        insertAttr.run(a, 'Color', rand(colors));
        insertAttr.run(a, 'Clouds', rand(clouds));
        insertAttr.run(a, 'Humidity (%)', String(Math.floor(Math.random() * 100) + 1));
    }
});
for (var s = 1; s <= numAccessions; s += BATCH) {
    var e = Math.min(s + BATCH - 1, numAccessions);
    insertAttrBatch(s, e);
    process.stdout.write('\rAttributes: ' + e + '/' + numAccessions);
}
console.log(' - done.');

db.close();

var sizeMB = (fs.statSync(dbPath).size / (1024 * 1024)).toFixed(1);
console.log('\nDatabase built: ' + sizeMB + ' MB');
console.log('Commit db/browser.db and deploy the standalone/ directory as a static site.');
