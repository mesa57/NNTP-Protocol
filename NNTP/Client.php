<?php

/**
 * Class Net_NNTP_Client
 */
class Net_NNTP_Client extends Net_NNTP_Protocol_Client
{
    /**
     * Information summary about the currently selected group.
     *
     * @var array
     */
    private $_selectedGroupSummary = null;

    /**
     * @var array
     */
    private $_overviewFormatCache = null;

    /**
     * Disable xzver support for now, causes too many incompatibilities (spotweb/spotweb github issue #1133).
     *
     * @var array
     */
    private $_supportXzver = false;

    /**
     * Connect to a server.
     *
     * @param null|string       $host       The hostname og IP-address of the NNTP-server to connect to, defaults to
     *                                      localhost.
     * @param null|false|string $encryption False|'tls'|'ssl', defaults to false.
     * @param null|int          $port       The port number to connect to, defaults to 119 or 563 depending on
     *                                      $encryption.
     * @param null|int          $timeout
     *
     * @return bool True when posting allowed, otherwise false.
     */
    public function connect($host = null, $encryption = null, $port = null, $timeout = null)
    {
        // v1.0.x API
        if (is_int($encryption)) {
            trigger_error('You are using deprecated API v1.0 in Net_NNTP_Client: connect() !', E_USER_NOTICE);
            $port       = $encryption;
            $encryption = null;
        }

        return parent::connect($host, $encryption, $port, $timeout);
    }

    /**
     * Disconnect from server.
     *
     * @return bool
     */
    public function disconnect()
    {
        return parent::disconnect();
    }

    /**
     * Deprecated alias for disconnect().
     *
     * @return bool
     * @deprecated Use disconnect instead.
     */
    public function quit()
    {
        return $this->disconnect();
    }

    /**
     * Authenticate. (Non-standard)
     * This method uses non-standard commands, which is not part
     * of the original RFC977, but has been formalized in RFC2890.
     *
     * @param string $user The username.
     * @param string $pass The password.
     *
     * @return bool True on successful authentication, otherwise false.
     */
    public function authenticate($user, $pass)
    {
        // Username is a must...
        if ($user == null) {
            $this->throwError('No username supplied', null);
        }

        return $this->cmdAuthinfo($user, $pass);
    }

    /**
     * Selects a group.
     * Moves the servers 'currently selected group' pointer to the group
     * a new group, and returns summary information about it. (Non-standard)
     * When using the second parameter,
     * This method uses non-standard commands, which is not part
     * of the original RFC977, but has been formalized in RFC2890.
     *
     * @param string $group    Name of the group to select.
     * @param mixed  $articles Experimental! When true the article numbers is returned in 'articles'.
     *
     * @return array Summary about the selected group
     */
    public function selectGroup($group, $articles = false)
    {
        /**
         * Select group, even if $articles is set, since many servers
         * don't select groups when the listgroup command is run.
         */
        $summary = $this->cmdGroup($group);

        // Store group info in the object
        $this->_selectedGroupSummary = $summary;

        if ($articles !== false) {
            $summary2 = $this->cmdListgroup($group, ($articles === true ? null : $articles));

            // Make sure the summary array is correct...
            if ($summary2['group'] == $group) {
                $summary = $summary2;
                // ... even if server does not include summary in status response.
            } else {
                $summary['articles'] = $summary2['articles'];
            }
        }

        return $summary;
    }

    /**
     * Select the previous article.
     * Select the previous article in current group.
     *
     * @param int $_ret Experimental
     *
     * @return mixed
     *  - (integer)    Article number, if $ret=0 (default)
     *  - (string)    Message-id, if $ret=1
     *  - (array)    Both article number and message-id, if $ret=-1
     *  - (bool)    False if no previous article exists
     */
    public function selectPreviousArticle($_ret = 0)
    {
        try {
            $response = $this->cmdLast();
        } catch (Exception $x) {
            return false;
        }

        switch ($_ret) {
            case -1:
                return ['Number' => (int)$response[0], 'Message-ID' => (string)$response[1]];
                break;
            case 0:
                return (int)$response[0];
                break;
            case 1:
                return (string)$response[1];
                break;
            default:
                //error(); // ...
        }
    }

    /**
     * Select the next article.
     * Select the next article in current group.
     *
     * @param int $_ret Experimental
     *
     * @return mixed <br>
     *  - (integer)    Article number, if $ret=0 (default)
     * - (string)    Message-id, if $ret=1
     * - (array)    Both article number and message-id, if $ret=-1
     * - (bool)    False if no further articles exist
     */
    public function selectNextArticle($_ret = 0)
    {
        $response = $this->cmdNext();

        switch ($_ret) {
            case -1:
                return ['Number' => (int)$response[0], 'Message-ID' => (string)$response[1]];
                break;
            case 0:
                return (int)$response[0];
                break;
            case 1:
                return (string)$response[1];
                break;
            default:
                //error(); // ...
        }
    }

    /**
     * Selects an article by article message-number.
     *
     * @param mixed $article The message-number (on the server) of
     *                       the article to select as current article.
     * @param int   $_ret     Experimental
     *
     * @return mixed
     * - (integer) Article number
     * - (bool)    False if article doesn't exists
     */
    public function selectArticle($article = null, $_ret = 0)
    {
        $response = $this->cmdStat($article);

		switch ($_ret) {
            case -1:
				return ['Number' => (int)$response[0], 'Message-ID' => (string)$response[1]];
                break;
            case 0:
                return (int)$response[0];
                break;
            case 1:
                return (string)$response[1];
                break;
            default:
				//error(); // ...
        }
    }

    /**
     * Fetch article into transfer object.
     * Select an article based on the arguments, and return the entire
     * article (raw data).
     *
     * @param mixed $article            Either the message-id or the
     *                                  message-number on the server of the
     *                                  article to fetch.
     * @param bool  $implode            When true the result array
     *                                  is imploded to a string, defaults to
     *                                  false.
     *
     * @return mixed
     *  - (array)    Complete article (when $implode is false)
     *  - (string)    Complete article (when $implode is true)
     *  - (object)    Pear_Error on failure
     */
    public function getArticle($article = null, $implode = false)
    {
        // v1.1.x API
        if (is_string($implode)) {
            trigger_error('You are using deprecated API v1.1 in Net_NNTP_Client: getHeader() !', E_USER_NOTICE);

            $class   = $implode;
            $implode = false;

            if (!class_exists($class)) {
                $this->throwError("Class '$class' does not exist!");
            }
        }

        $data = $this->cmdArticle($article);

        if ($implode == true) {
            $data = implode("\r\n", $data);
        }

        // v1.1.x API
        if (isset($class)) {
			return $obj = new $class($data);
        }

        return $data;
    }

    /**
     * Fetch article header.
     * Select an article based on the arguments, and return the article
     * header (raw data).
     *
     * @param mixed $article            Either message-id or message
     *                                  number of the article to fetch.
     * @param bool  $implode            When true the result array
     *                                  is imploded to a string, defaults to
     *                                  false.
     *
     * @return mixed
     *  - (bool)    False if article does not exist
     *  - (array)    Header fields (when $implode is false)
     *  - (string)    Header fields (when $implode is true)
     */
    public function getHeader($article = null, $implode = false)
    {
        // v1.1.x API
        if (is_string($implode)) {
            trigger_error('You are using deprecated API v1.1 in Net_NNTP_Client: getHeader() !', E_USER_NOTICE);

            $class   = $implode;
            $implode = false;

            if (!class_exists($class)) {
                $this->throwError("Class '$class' does not exist!");
            }
        }

        $data = $this->cmdHead($article);

        if ($implode == true) {
            $data = implode("\r\n", $data);
        }

        // v1.1.x API
        if (isset($class)) {
			return $obj = new $class($data);
        }

        return $data;
    }

    /**
     * Fetch article body.
     * Select an article based on the arguments, and return the article
     * body (raw data).
     *
     * @param mixed $article            Either the message-id or the
     *                                  message-number on the server of the
     *                                  article to fetch.
     * @param bool  $implode            When true the result array
     *                                  is imploded to a string, defaults to
     *                                  false.
     *
     * @return mixed
     *  - (array)    Message body (when $implode is false)
     *  - (string)    Message body (when $implode is true)
     */
    public function getBody($article = null, $implode = false)
    {
        // v1.1.x API
        if (is_string($implode)) {
            trigger_error('You are using deprecated API v1.1 in Net_NNTP_Client: getHeader() !', E_USER_NOTICE);

            $class   = $implode;
            $implode = false;

            if (!class_exists($class)) {
                $this->throwError("Class '$class' does not exist!");
            }
        }

        $data = $this->cmdBody($article);

        if ($implode == true) {
            $data = implode("\r\n", $data);
        }

        // v1.1.x API
        if (isset($class)) {
			return $obj = new $class($data);
        }

        return $data;
    }

    /**
     * Post a raw article to a number of groups.
     *
     * @param mixed $article - (string) Complete article in a ready to send format (lines terminated by LFCR etc.)
     *                       - (array) First key is the article header, second key is article body - any further keys
     *                       are ignored !!!
     *                       - (mixed) Something 'callable' (which must return otherwise acceptable data as
     *                       replacement)
     *
     * @return string Server response.
     */
    public function post($article)
    {
        // API v1.0
        if (func_num_args() >= 4) {
            trigger_error('You are using deprecated API v1.0 in Net_NNTP_Client: post() !', E_USER_NOTICE);

            $groups     = func_get_arg(0);
            $subject    = func_get_arg(1);
            $body       = func_get_arg(2);
            $from       = func_get_arg(3);
            $additional = func_get_arg(4);

            return $this->mail($groups, $subject, $body, "From: $from\r\n" . $additional);
        }

        // Only accept $article if array or string
        if (!is_array($article) && !is_string($article)) {
            $this->throwError('Ups', null, 0);
        }

        // Check if server will receive an article
        $post = $this->cmdPost();

        // Get article data from callback function
        if (is_callable($article)) {
            $article = call_user_func($article);
        }

        // Actually send the article
        return $this->cmdPost2($article);
    }

    /**
     * Post an article to a number of groups - using same parameters as PHP's mail() function.
     * Among the aditional headers you might think of adding could be:
     * "From: <author-email-address>", which should contain the e-mail address
     * of the author of the article.
     * Or "Organization: <org>" which contain the name of the organization
     * the post originates from.
     * Or "NNTP-Posting-Host: <ip-of-author>", which should contain the IP-address
     * of the author of the post, so the message can be traced back to him.
     *
     * @param string $groups     The groups to post to.
     * @param string $subject    The subject of the article.
     * @param string $body       The body of the article.
     * @param string $additional Additional header fields to send.
     *
     * @return string Server response.
     */
    public function mail($groups, $subject, $body, $additional = null)
    {
        // Check if server will receive an article
        $post = $this->cmdPost();

        // Construct header
        $header = "Newsgroups: $groups\r\n";
        $header .= "Subject: $subject\r\n";
        $header .= "X-poster: PEAR::Net_NNTP v1.5.0RC1 (beta)\r\n";
        if ($additional !== null) {
            $header .= $additional;
        }
        $header .= "\r\n";

        // Actually send the article
		return $this->cmdPost2([$header, $body]);
    }

    /**
     * Get the server's internal date (Non-standard)
     * This method uses non-standard commands, which is not part
     * of the original RFC977, but has been formalized in RFC2890.
     *
     * @param int $format        Determines the format of returned date:
     *                           - 0: return string
     *                           - 1: return integer/timestamp
     *                           - 2: return an array('y'=>year, 'm'=>month,'d'=>day)
     *
     * @return mixed
     */
    public function getDate($format = 1)
    {
        $date = $this->cmdDate();

        switch ($format) {
            case 0:
                return $date;
                break;
            case 1:
                return strtotime(
                    substr($date, 0, 8) . ' ' .
                    substr($date, 8, 2) . ':' .
                    substr($date, 10, 2) . ':' .
                    substr($date, 12, 2)
                );
                break;
            case 2:
				return [
                    'y' => substr($date, 0, 4),
                    'm' => substr($date, 4, 2),
                    'd' => substr($date, 6, 2)
				];
                break;
            default:
				error();
        }
    }

    /**
     * Get new groups since a date.
     * Returns a list of groups created on the server since the specified date
     * and time.
     *
     * @param mixed  $time
     *             - (integer)    A timestamp
     *             - (string)    Somthing parseable by strtotime() like '-1 week'
     * @param string $distributions
     *
     * @return array
     */
    public function getNewGroups($time, $distributions = null)
    {
        switch (true) {
            case is_integer($time):
                break;
            case is_string($time):
                $time = strtotime($time);
                if ($time === false || ($time === -1 && version_compare(phpversion(), '5.1.0', '<'))) {
                    $this->throwError('$time could not be converted into a timestamp!', null, 0);
                }
                break;
            default:
                trigger_error('$time must be either a string or an integer/timestamp!', E_USER_ERROR);
        }

        return $this->cmdNewgroups($time, $distributions);
    }

    /**
     * Get new articles since a date.
     * Returns a list of message-ids of new articles (since the specified date
     * and time) in the groups whose names match the wildmat
     *
     * @param mixed  $time
     *                              - (integer)    A timestamp
     *                              - (string)    Somthing parseable by strtotime() like '-1 week'
     * @param string $groups
     * @param string $distributions
     *
     * @return array
     */
    public function getNewArticles($time, $groups = '*', $distribution = null)
    {
        switch (true) {
            case is_integer($time):
                break;
            case is_string($time):
                $time = strtotime($time);
                if ($time === false || ($time === -1 && version_compare(php_version(), '5.1.0', '<'))) {
                    $this->throwError('$time could not be converted into a timestamp!', null, 0);
                }
                break;
            default:
                trigger_error('$time must be either a string or an integer/timestamp!', E_USER_ERROR);
        }

        return $this->cmdNewnews($time, $groups, $distribution);
    }

    /**
     * Fetch valid groups.
     * Returns a list of valid groups (that the client is permitted to select)
     * and associated information.
     *
     * @param null $wildmat
     *
     * @return array Nested array with information about every valid group
     * @throws Exception
     */
    public function getGroups($wildmat = null)
    {
        $backup = false;

        // Get groups
        try {
            $groups = $this->cmdListActive($wildmat);
		} catch (NntpException $x) {
            switch ($x->getCode()) {
                case 500:
                case 501:
                    $backup = true;
                    break;
                default:
                    throw $x;
            }
        }

        if ($backup == true) {
            if (!is_null($wildmat)) {
                $this->throwError(
                    "The server does not support the 'LIST ACTIVE' command, and the 'LIST' command does not support the wildmat parameter!",
                    null,
                    null
                );
            }

            $groups2 = $this->cmdList();
        }

        return $groups;
    }

    /**
     * Fetch all known group descriptions.
     * Fetches a list of known group descriptions - including groups which
     * the client is not permitted to select. (Non-standard)
     * This method uses non-standard commands, which is not part
     * of the original RFC977, but has been formalized in RFC2890.
     *
     * @param mixed $wildmat (optional)
     *
     * @return array Associated array with descriptions of known groups
     */
    public function getDescriptions($wildmat = null)
    {
        if (is_array($wildmat)) {
            $wildmat = implode(',', $wildmat);
        }

        // Get group descriptions
        $descriptions = $this->cmdListNewsgroups($wildmat);

        return $descriptions;
    }

    /**
     * Fetch an overview of article(s) in the currently selected group.
     * Returns the contents of all the fields in the database for a number
     * of articles specified by either article-numnber range, a message-id,
     * or nothing (indicating currently selected article).
     * The first 8 fields per article is always as follows:
     *   - 'Number' - '0' or the article number of the currently selected group.
     *   - 'Subject' - header content.
     *   - 'From' - header content.
     *   - 'Date' - header content.
     *   - 'Message-ID' - header content.
     *   - 'References' - header content.
     *   - ':bytes' - metadata item.
     *   - ':lines' - metadata item.
     * The server may send more fields form it's database... (Non-standard)
     * This method uses non-standard commands, which is not part
     * of the original RFC977, but has been formalized in RFC2890.
     *
     * @param mixed $range
     *                             - '<message number>'
     *                             - '<message number>-<message number>'
     *                             - '<message number>-'
     *                             - '<message-id>'
     * @param bool  $_names        Experimental parameter! Use field names as array keys.
     * @param bool  $_forceNames   Experimental parameter!
     *
     * @return array Nested array of article overview data.
     */
    public function getOverview($range = null, $_names = true, $_forceNames = true)
    {
        // API v1.0
        switch (true) {
            // API v1.3
            case func_num_args() != 2:
            case is_bool(func_get_arg(1)):
            case !is_int(func_get_arg(1)) || (is_string(func_get_arg(1)) && ctype_digit(func_get_arg(1))):
            case !is_int(func_get_arg(0)) || (is_string(func_get_arg(0)) && ctype_digit(func_get_arg(0))):
                break;

            default:
                //
                trigger_error('You are using deprecated API v1.0 in Net_NNTP_Client: getOverview() !', E_USER_NOTICE);

                // Fetch overview via API v1.3
                $overview = $this->getOverview(func_get_arg(0) . '-' . func_get_arg(1), true, false);
                if (PEAR::isError($overview)) {
                    return $overview;
                }

                // Create and return API v1.0 compliant array
				$articles = [];
                foreach ($overview as $article) {
                    // Rename 'Number' field into 'number'
					$article = array_merge(['number' => array_shift($article)], $article);

                    // Use 'Message-ID' field as key
                    $articles[$article['Message-ID']] = $article;
                }

                return $articles;
        }

        // Fetch overview from server
        $overview = $this->cmdXOver($range);
        if (PEAR::isError($overview)) {
            return $overview;
        }

        // Use field names from overview format as keys?
        if ($_names) {
            // Already cached?
            if (is_null($this->_overviewFormatCache)) {
                // Fetch overview format
                $format = $this->getOverviewFormat($_forceNames, true);
                if (PEAR::isError($format)) {
                    return $format;
                }

                // Prepend 'Number' field
                $format = array_merge(['Number' => false], $format);

                // Cache format
                $this->_overviewFormatCache = $format;
                //
            } else {
                $format = $this->_overviewFormatCache;
            }

            // Loop through all articles
            foreach ($overview as $key => $article) {
                // Copy $format
                $f = $format;

                // Field counter
                $i = 0;

                // Loop through forld names in format
                foreach ($f as $tag => $full) {
                    $f[$tag] = $article[$i++];

                    // If prefixed by field name, remove it
                    if ($full === true) {
                        $f[$tag] = ltrim(substr($f[$tag], strpos($f[$tag], ':') + 1), " \t");
                    }
                }

                // Replace article
                $overview[$key] = $f;
            }
        }

        switch (true) {
            // Expect one article
            case is_null($range);
            case is_int($range);
            case is_string($range) && ctype_digit($range):
            case is_string($range) && substr($range, 0, 1) == '<' && substr($range, -1, 1) == '>':
                if (count($overview) == 0) {
                    return false;
                } else {
                    return reset($overview);
                }
                break;

            // Expect multiple articles
            default:
                return $overview;
        }
    }

    /**
     * Fetch names of fields in overview database
     * Returns a description of the fields in the database for which it is consistent. (Non-Standard)
     * This method uses non-standard commands, which is not part
     * of the original RFC977, but has been formalized in RFC2890.
     *
     * @return array Overview field names.
     */
    public function getOverviewFormat($_forceNames = true, $_full = false)
    {
        $format = $this->cmdListOverviewFmt();

        // Force name of first seven fields
        if ($_forceNames) {
            array_splice($format, 0, 7);
			$format = array_merge([
                'Subject' => false,
                'From' => false,
                'Date' => false,
                'Message-ID' => false,
                'References' => false,
                ':bytes' => false,
                ':lines' => false
			], $format);
        }

        if ($_full) {
            return $format;
        } else {
            return array_keys($format);
        }
    }

    /**
     * Fetch content of a header field from message(s).
     * Retreives the content of specific header field from a number of messages.
     * <b>Non-standard!</b><br>
     * This method uses non-standard commands, which is not part
     * of the original RFC977, but has been formalized in RFC2890.
     *
     * @param string $field       The name of the header field to retreive
     * @param mixed  $range       (optional)
     *                            '<message number>'
     *                            '<message number>-<message number>'
     *                            '<message number>-'
     *                            '<message-id>'
     *
     * @return array Nested array of.
     */
    public function getHeaderField($field, $range = null)
    {
        $fields = $this->cmdXHdr($field, $range);

        switch (true) {
            // Expect one article
            case is_null($range);
            case is_int($range);
            case is_string($range) && $this->isDigit($range):
            case is_string($range) && substr($range, 0, 1) == '<' && substr($range, -1, 1) == '>':

                if (count($fields) == 0) {
                    return false;
                } else {
                    return reset($fields);
                }
                break;

            // Expect multiple articles
            default:
                return $fields;
        }
    }

    /**
     * Non-standard, this method uses non-standard commands, which is not part
     * of the original RFC977, but has been formalized in RFC2890.
     *
     * @param mixed $range Experimental!
     *
     * @return array
     */
    public function getGroupArticles($range = null)
    {
        $summary = $this->cmdListgroup();

        // Update summary cache if group was also 'selected'
        if ($summary['group'] !== null) {
            $this->_selectedGroupSummary = $summary;
        }

        return $summary['articles'];
    }

    /**
     * Fetch reference header field of message(s).
     * Retrieves the content of the references header field of messages via
     * either the XHDR ord the XROVER command.
     * Identical to getHeaderField('References'). (Non-standard)
     * This method uses non-standard commands, which is not part
     * of the original RFC977, but has been formalized in RFC2890.
     *
     * @param mixed $range        (optional)
     *                            '<message number>'
     *                            '<message number>-<message number>'
     *                            '<message number>-'
     *                            '<message-id>'
     *
     * @return array Nested array of references.
     * @throws Exception
     */
    public function getReferences($range = null)
    {
        $backup = false;

        try {
            $references = $this->cmdXHdr('References', $range);
		} catch (NntpException $x) {
            switch ($x->getCode()) {
                case 500:
                case 501:
                    $backup = true;
                    break;
                default:
                    throw $x;
            }
        }

        if (true && (is_array($references) && count($references) == 0)) {
            $backup = true;
        }

        if ($backup == true) {
            $references2 = $this->cmdXROver($range);
            $references  = $references2;
        }

        if (is_array($references)) {
            foreach ($references as $key => $val) {
                $references[$key] = preg_split("/ +/", trim($val), -1, PREG_SPLIT_NO_EMPTY);
            }
        }

        switch (true) {
            // Expect one article
            case is_null($range);
            case is_int($range);
            case is_string($range) && $this->isDigit($range):
            case is_string($range) && substr($range, 0, 1) == '<' && substr($range, -1, 1) == '>':
                if (count($references) == 0) {
                    return false;
                } else {
                    return reset($references);
                }
                break;

            // Expect multiple articles
            default:
                return $references;
        }
    }

    /**
     * Number of articles in currently selected group.
     *
     * @return string the number of article in group.
     */
    public function count()
    {
        return $this->_selectedGroupSummary['count'];
    }

    /**
     * Maximum article number in currently selected group.
     *
     * @return string the last article's number.
     */
    public function last()
    {
        return $this->_selectedGroupSummary['last'];
    }

    /**
     * Minimum article number in currently selected group.
     *
     * @return string The first article's number.
     */
    public function first()
    {
        return $this->_selectedGroupSummary['first'];
    }

    /**
     * Currently selected group.
     *
     * @return string Group name.
     */
    public function group()
    {
        return $this->_selectedGroupSummary['group'];
    }

    /**
     * Test whether a connection is currently open or closed.
     *
     * @return bool True if connected, otherwise false.
     * @deprecated Due to use of protected method: Net_NNTP_Protocol_Client::isConnected().
     */
    public function isConnected()
    {
        trigger_error('You are using deprecated API v1.0 in Net_NNTP_Client: isConnected() !', E_USER_NOTICE);

        return parent::_isConnected();
    }

    /**
     * Deprecated alias for getArticle().
     *
     * @deprecated use getArticle instead.
     */
    public function getArticleRaw($article, $implode = false)
    {
        trigger_error('You are using deprecated API v1.0 in Net_NNTP_Client: getArticleRaw() !', E_USER_NOTICE);

        return $this->getArticle($article, $implode);
    }

    /**
     * Deprecated alias for getHeader().
     *
     * @deprecated use getHeader instead.
     */
    public function getHeaderRaw($article = null, $implode = false)
    {
        trigger_error('You are using deprecated API v1.0 in Net_NNTP_Client: getHeaderRaw() !', E_USER_NOTICE);

        return $this->getHeader($article, $implode);
    }

    /**
     * Deprecated alias for getBody()
     *
     * @deprecated use getBody instead.
     */
    public function getBodyRaw($article = null, $implode = false)
    {
        trigger_error('You are using deprecated API v1.0 in Net_NNTP_Client: getBodyRaw() !', E_USER_NOTICE);

        return $this->getBody($article, $implode);
    }

    /**
     * Deprecated alias for getNewArticles()
     *
     * @deprecated use getNewArticles instead.
     */
    public function getNewNews($time, $groups = '*', $distribution = null)
    {
        trigger_error('You are using deprecated API v1.1 in Net_NNTP_Client: getNewNews() !', E_USER_NOTICE);

        return $this->getNewArticles($time, $groups, $distribution);
    }

    /**
     * Deprecated alias for getReferences()
     *
     * @deprecated use getReferences instead.
     */
    public function getReferencesOverview($first, $last)
    {
        trigger_error('You are using deprecated API v1.0 in Net_NNTP_Client: getReferencesOverview() !', E_USER_NOTICE);

        return $this->getReferences($first . '-' . $last);
    }

    /**
     * @param string $s
     *
     * @return bool
     */
    private function isDigit($s)
    {
        for ($i = 0; $i < strlen($s); $i++) {
            if (strpos('0123456789', $s[$i]) === false) {
                return false;
            }
        }

        return true;
    }
}
