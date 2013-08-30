symfony1.4-sqlite-cache
=======================

An alternate sfSQLiteCache implementation for symfony v1.4 that works properly on php 5.4 and above.

If you are still using symfony 1.4, or have to maintain old projects based on that version and have upgraded to php 5.4,
while using sfSQLiteCache as view_cache, then you will experience errors, because php5.4 has dropped support for the sqlite
implementation that sfSQLiteCache is using.

This class is a replacement that works correctly on php 5.4. While i have not unit tested it, i have used it on several projects and so far it has proved to work correctly.

Usage
-----

Copy the class file in your lib folder and then alter your factories.yml file

    all:

      view_cache:
        class: AlternateSQLiteCache
        param:
          lifetime: 3600 // your value hear
          database: %SF_TEMPLATE_CACHE_DIR%/cache.db
