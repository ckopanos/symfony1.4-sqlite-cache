<?php
/**
 * AlternateSQLiteCache is an sfSQLiteCache alternative that works correctly on php5.4.
 *
 * @author     Christos Kopanos <ckopanos@redmob.gr>
 */

class AlternateSQLiteCache extends sfCache {
    protected
        $dbh      = null,
        $database = '';

    /**
     * Initializes this sfCache instance.
     *
     * Available options:
     *
     * * database: File where to put the cache database
     *
     * * see sfCache for options available for all drivers
     *
     * @see sfCache
     */
    public function initialize($options = array())
    {
        if (!extension_loaded('SQLite3') && !extension_loaded('pdo_SQLite'))
        {
            throw new sfConfigurationException('AlternateSQliteCache class needs "sqlite3" or "pdo_sqlite" extension to be loaded.');
        }

        parent::initialize($options);

        if (!$this->getOption('database'))
        {
            throw new sfInitializationException('You must pass a "database" option to initialize a AlternateSQliteCache object.');
        }

        $this->setDatabase($this->getOption('database'));
    }

    /**
     * @see sfCache
     */
    public function getBackend()
    {
        return $this->dbh;
    }

    /**
     * @see sfCache
     */
    public function get($key, $default = null)
    {
        $stmt = $this->dbh->prepare('SELECT data FROM cache WHERE key = :key AND timeout > :timeout');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':timeout', time(), SQLITE3_INTEGER);

        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return false === $result ? $default : $result['data'];
    }

    /**
     * @see sfCache
     */
    public function has($key)
    {
        $stmt = $this->dbh->prepare('SELECT key FROM cache WHERE key = :key AND timeout > :timeout');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':timeout', time(), SQLITE3_INTEGER);
        return (boolean) $result = $stmt->execute()->fetchArray();
    }

    /**
     * @see sfCache
     */
    public function set($key, $data, $lifetime = null)
    {
        if ($this->getOption('automatic_cleaning_factor') > 0 && rand(1, $this->getOption('automatic_cleaning_factor')) == 1)
        {
            $this->clean(sfCache::OLD);
        }
        $stmt = $this->dbh->prepare('INSERT OR REPLACE INTO cache (key, data, timeout, last_modified) VALUES (:p1, :p2, :p3, :p4)');
        $stmt->bindValue(':p1', $key, SQLITE3_TEXT);
        $stmt->bindValue(':p2', $data, SQLITE3_TEXT);
        $stmt->bindValue(':p3', time() + $this->getLifetime($lifetime), SQLITE3_INTEGER);
        $stmt->bindValue(':p4', time(), SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray();
        return (boolean) $result;
    }

    /**
     * @see sfCache
     */
    public function remove($key)
    {
        $stmt = $this->dbh->prepare('DELETE FROM cache WHERE key = :key');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        return (boolean) $stmt->execute()->fetchArray();
    }

    /**
     * @see sfCache
     */
    public function removePattern($pattern)
    {
        $stmt = $this->dbh->prepare('DELETE FROM cache WHERE REGEXP(:key,key)');
        $stmt->bindValue(':key', self::patternToRegexp($pattern), SQLITE3_TEXT);
        return (boolean) $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    }

    /**
     * @see sfCache
     */
    public function clean($mode = sfCache::ALL)
    {
        return (boolean) $this->dbh->query("DELETE FROM cache".(sfCache::OLD == $mode ? sprintf(" WHERE timeout < '%s'", time()) : ''));
    }

    /**
     * @see sfCache
     */
    public function getTimeout($key)
    {
        $stmt = $this->dbh->prepare('SELECT timeout FROM cache WHERE key = :key AND timeout > :timeout');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':timeout', time(), SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        return $result ? intval($result['timeout']) : 0;
    }

    /**
     * @see sfCache
     */
    public function getLastModified($key)
    {
        $stmt = $this->dbh->prepare('SELECT last_modified FROM cache WHERE key = :key AND timeout > :timeout');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':timeout', time(), SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);


        return $result ? intval($result['last_modified']) : 0;
    }

    /**
     * Sets the database name.
     *
     * @param string $database The database name where to store the cache
     */
    protected function setDatabase($database)
    {
        $this->database = $database;

        $new = false;
        if (':memory:' == $database)
        {
            $new = true;
        }
        else if (!is_file($database))
        {
            $new = true;

            // create cache dir if needed
            $dir = dirname($database);
            $current_umask = umask(0000);
            if (!is_dir($dir))
            {
                @mkdir($dir, 0777, true);
            }

            touch($database);
            umask($current_umask);
        }

        if (!$this->dbh = new SQLite3($this->database))
        {
            throw new sfCacheException(sprintf('Unable to connect to SQLite database'));
        }

        $this->dbh->createFunction('regexp', array($this, 'removePatternRegexpCallback'), 2);

        if ($new)
        {
            $this->createSchema();
        }
    }

    /**
     * Callback used when deleting keys from cache.
     */
    public function removePatternRegexpCallback($regexp, $key)
    {
        return preg_match($regexp, $key);
    }

    /**
     * @see sfCache
     */
    public function getMany($keys)
    {
        $stmt = $this->dbh->prepare('SELECT key, data FROM cache WHERE key IN (:keys) AND timeout > :timeout');
        $stmt->bindValue(':keys', implode('\', \'', $keys), SQLITE3_TEXT);
        $stmt->bindValue(':timeout', time(), SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        $data = array();
        foreach ($result as $row)
        {
            $data[$row['key']] = $row['data'];
        }

        return $data;
    }

    /**
     * Creates the database schema.
     *
     * @throws sfCacheException
     */
    protected function createSchema()
    {
        $statements = array(
            'CREATE TABLE [cache] (
              [key] VARCHAR(255),
              [data] LONGVARCHAR,
              [timeout] TIMESTAMP,
              [last_modified] TIMESTAMP
            )',
            'CREATE UNIQUE INDEX [cache_unique] ON [cache] ([key])',
        );

        foreach ($statements as $statement)
        {
            if (!$this->dbh->query($statement))
            {
                throw new sfCacheException(sqlite_error_string($this->dbh->lastError()));
            }
        }
    }
}