# modulus-sync

A script used to synchronize AUFO's specific Omeka S instance from a CSV source using the REST API.

The script updates a set of metadata related to modules/authors items via the Omeka S REST API. The metadata are provided by the CSV file produced by Daniel-KM, which can be downloaded from the page https://daniel-km.github.io/UpgradeToOmekaS/en/omeka_s_modules.html.

The script is written in PHP (tested with version 8.3) and runs from the command line.

Current version: v0.3

This script is still under active development. It is recommended to wait for the v1.0 release before using it on a production environment.

## Installation

After copying the script file in a directory, create a JSON configuration file.

## Configuration file

The configuration file is in JSON format. It contains settings describing:
-	API connection details (endpoint, access keys),
-	information on Omeka properties for modules and authors, as well as relevant columns from the source file,
-	Omeka metadata structures for modules and authors.

## Usage

For each row in the file, the script identifies the module using its unique ID, then updates or creates it depending on whether it already exists in the database. If creating a module, the script looks for the author and creates them if necessary.

In the event of a duplicate module ID (e.g., a fork), the script uses the most recent release date. In the event of a duplicate author ID (e.g., an erroneous database entry), the script selects the first occurrence.

## Copyright

Copyright 2026 Christian Morel

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
