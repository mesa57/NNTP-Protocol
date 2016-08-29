<?php

/**
 * Default host
 */
const NET_NNTP_PROTOCOL_CLIENT_DEFAULT_HOST = 'localhost';

/**
 * Default port
 */
const NET_NNTP_PROTOCOL_CLIENT_DEFAULT_PORT = '119';

/**
 * Class Net_NNTP_Protocol_Client
 */
class Net_NNTP_Protocol_Client
{
    /**
     * The socket resource being used to connect to the NNTP server.
     *
     * @var resource
     */
    private $_socket = null;

    /**
     * Contains the last recieved status response code and text.
     *
     * @var array
     */
    private $_currentStatusResponse = null;

    /**
     * @var object
     */
    private $_logger = null;

    /**
     * Contains false on non-ssl connection and true when ssl.
     *
     * @var object
     */
    private $_ssl = false;

    /**
     * @return string
     */
    public function getPackageVersion()
    {
        return '1.5.0RC1';
    }

    /**
     * @return string
     */
    public function getApiVersion()
    {
        return '0.9.0';
    }

    /**
     * @param object $logger
     */
    protected function setLogger($logger)
    {
        $this->_logger = $logger;
    }

    /**
     * @param bool $debug
     *
     * @deprecated Is handled through logger.
     */
    protected function setDebug($debug = true)
    {
        trigger_error('You are using deprecated API v1.0 in Net_NNTP_Protocol_Client: setDebug() ! Debugging in now automatically handled when a logger is given.',
            E_USER_NOTICE);
    }

    /**
     * Clears ssl errors from the openssl error stack.
     */
    public function _clearSSLErrors()
    {
        if ($this->_ssl) {
            while ($msg = openssl_error_string()) {
            };
        }
    }

    /**
     * Send a command to the server. A carriage return / linefeed (CRLF) sequence
     * will be appended to each command string before it is sent to the IMAP server.
     *
     * @param string $cmd The command to launch, ie: "ARTICLE 1004853".
     *
     * @return int Response code on success.
     */
    private function _sendCommand($cmd)
    {
        // NNTP/RFC977 only allows command up to 512 (-2) chars.
        if (!strlen($cmd) > 510) {
            $this->throwError('Failed writing to socket! (Command to long - max 510 chars)');
        }

        /**
         * Prevent new line (and possible future) characters in the NNTP commands
         * Net_NNTP does not support pipelined commands. Inserting a new line charecter
         * allows sending multiple commands and thereby making the communication between
         * NET_NNTP and the server out of sync...
         */
        if (preg_match_all('/\r?\n/', $cmd, $matches, PREG_PATTERN_ORDER)) {
            foreach ($matches[0] as $key => $match) {
                $this->_logger->debug("Illegal character in command: " . htmlentities(str_replace(["\r", "\n"],
                        ["'Carriage Return'", "'New Line'"], $match)));
            }
            $this->throwError("Illegal character(s) in NNTP command!");
        }

        // Check if connected.
        if (!$this->_isConnected()) {
            $this->throwError('Failed to write to socket! (connection lost!)', -999);
        }

        // Send the command.
        $this->_clearSSLErrors();
        $R = @fwrite($this->_socket, $cmd . "\r\n");
        if ($R === false) {
            $this->throwError('Failed to write to socket!');
        }

        if ($this->_logger && $this->_logger->_isMasked(PEAR_LOG_DEBUG)) {
            $this->_logger->debug('C: ' . $cmd);
        }

        return $this->_getStatusResponse();
    }

    /**
     * Get servers status response after a command.
     *
     * @return int status code on success.
     */
    private function _getStatusResponse()
    {
        // Retrieve a line (terminated by "\r\n") from the server.
        $this->_clearSSLErrors();
        $response = @fgets($this->_socket);
        if ($response === false) {
            $this->throwError('Failed to read from socket...!');
        }

        $streamStatus = stream_get_meta_data($this->_socket);
        if ($streamStatus['timed_out']) {
            $this->throwError('Connection timed out');
        }

        //
        if ($this->_logger && $this->_logger->_isMasked(PEAR_LOG_DEBUG)) {
            $this->_logger->debug('S: ' . rtrim($response, "\r\n"));
        }

        // Trim the start of the response in case of misplaced whitespace (should not be needed).
        $response = ltrim($response);

        $this->_currentStatusResponse = [
            (int)substr($response, 0, 3),
            (string)rtrim(substr($response, 4))
        ];

        //
        return $this->_currentStatusResponse[0];
    }

    /**
     * Retrieve textural data.
     * Get data until a line with only a '.' in it is read and return data.
     *
     * @return array Text response on success.
     */
    private function _getTextResponse()
    {
        $data = [];
        $line = '';

        //
        $debug = $this->_logger && $this->_logger->_isMasked(PEAR_LOG_DEBUG);

        // Continue until connection is lost
        while (!feof($this->_socket)) {

            // Retrieve and append up to 8192 characters from the server.
            $this->_clearSSLErrors();
            $recieved = @fgets($this->_socket, 8192);

            if ($recieved === false) {
                $this->throwError('Failed to read line from socket.', null);
            }

            $streamStatus = stream_get_meta_data($this->_socket);
            if ($streamStatus['timed_out']) {
                $this->throwError('Connection timed out');
            }

            $line .= $recieved;

            // Continue if the line is not terminated by CRLF.
            if (substr($line, -2) != "\r\n" || strlen($line) < 2) {
                usleep(25000);
                continue;
            }

            // Validate received line.
            if (false) {
                // Lines should/may not be longer than 998+2 chars (RFC2822 2.3).
                if (strlen($line) > 1000) {
                    if ($this->_logger) {
                        $this->_logger->notice('Max line length...');
                    }
                    $this->throwError('Invalid line recieved!', null);
                }
            }

            // Remove CRLF from the end of the line.
            $line = substr($line, 0, -2);

            // Check if the line terminates the text response.
            if ($line == '.') {
                if ($this->_logger) {
                    $this->_logger->debug('T: ' . $line);
                }

                // return all previous lines
                return $data;
            }

            // If 1st char is '.' it's doubled (NNTP/RFC977 2.4.1).
            if (substr($line, 0, 2) == '..') {
                $line = substr($line, 1);
            }

            if ($debug) {
                $this->_logger->debug('T: ' . $line);
            }

            // Add the line to the array of lines.
            $data[] = $line;

            // Reset/empty $line.
            $line = '';
        }

        if ($this->_logger) {
            $this->_logger->warning('Broke out of reception loop! This souldn\'t happen unless connection has been lost?');
        }

        $this->throwError('End of stream! Connection lost?', null);
    }

    /**
     * Retrieve blob.
     * Get data and assume we do not hit any blind spots.
     *
     * @return array Text response on success.
     */
    private function _getCompressedResponse()
    {
        $data = [];

        /**
         * We can have two kinds of compressed support:
         * - yEnc encoding
         * - Just a gzip drop
         * We try to autodetect which one this uses.
         */
        $line = @fread($this->_socket, 1024);

        if (substr($line, 0, 7) == '=ybegin') {
            $data = $this->_getTextResponse();
            $data = $line . "\r\n" . implode("", $data);
            $data = $this->yencDecode($data);
            $data = explode("\r\n", gzinflate($data));

            return $data;
        }

        // We cannot use blocked I/O on this one.
        $streamMetadata = stream_get_meta_data($this->_socket);
        stream_set_blocking($this->_socket, false);

        // Continue until connection is lost or we don't receive any data anymore.
        $tries        = 0;
        $uncompressed = '';

        while (!feof($this->_socket)) {
            // Retrieve and append up to 32k characters from the server.
            $received = @fread($this->_socket, 32768);
            if (strlen($received) == 0) {
                $tries++;

                // Try decompression.
                $uncompressed = @gzuncompress($line);
                if (($uncompressed !== false) || ($tries > 500)) {
                    break;
                }

                if ($tries % 50 == 0) {
                    usleep(50000);
                }
            }

            // A error occurred.
            if ($received === false) {
                @fclose($this->_socket);
                $this->_socket = false;
            }

            $line .= $received;
        }

        // Set the stream to its original blocked(?) value.
        stream_set_blocking($this->_socket, $streamMetadata['blocked']);
        $data      = explode("\r\n", $uncompressed);
        $dataCount = count($data);

        // Gzipped compress includes the "." and linefeed in the compressed stream.
        // skip those.
        if ($dataCount >= 2) {
            if (($data[($dataCount - 2)] == ".") && (empty($data[($dataCount - 1)]))) {
                array_pop($data);
                array_pop($data);
            }

            $data = array_filter($data);
        }

        return $data;
    }

    /**
     * @param mixed $article
     *
     * @return bool
     */
    private function _sendArticle($article)
    {
        // data should be in the format specified by RFC850.
        switch (true) {
            case is_string($article):
                $this->_clearSSLErrors();
                @fwrite($this->_socket, $article);
                $this->_clearSSLErrors();
                @fwrite($this->_socket, "\r\n.\r\n");

                if ($this->_logger && $this->_logger->_isMasked(PEAR_LOG_DEBUG)) {
                    foreach (explode("\r\n", $article) as $line) {
                        $this->_logger->debug('D: ' . $line);
                    }
                    $this->_logger->debug('D: .');
                }
                break;

            case is_array($article):
                $header = reset($article);
                $body   = next($article);

                // Send header (including separation line).
                $this->_clearSSLErrors();
                @fwrite($this->_socket, $header);
                $this->_clearSSLErrors();
                @fwrite($this->_socket, "\r\n");

                if ($this->_logger && $this->_logger->_isMasked(PEAR_LOG_DEBUG)) {
                    foreach (explode("\r\n", $header) as $line) {
                        $this->_logger->debug('D: ' . $line);
                    }
                }

                // Send body.
                $this->_clearSSLErrors();
                @fwrite($this->_socket, $body);
                $this->_clearSSLErrors();
                @fwrite($this->_socket, "\r\n.\r\n");

                if ($this->_logger && $this->_logger->_isMasked(PEAR_LOG_DEBUG)) {
                    foreach (explode("\r\n", $body) as $line) {
                        $this->_logger->debug('D: ' . $line);
                    }
                    $this->_logger->debug('D: .');
                }
                break;

            default:
                $this->throwError('Ups...', null, null);
        }

        return true;
    }

    /**
     * @return string status text.
     */
    private function _currentStatusResponse()
    {
        return $this->_currentStatusResponse[1];
    }

    /**
     * @param int    $code Status code number.
     * @param string $text Status text.
     */
    private function _handleUnexpectedResponse($code = null, $text = null)
    {
        if ($code === null) {
            $code = $this->_currentStatusResponse[0];
        }

        if ($text === null) {
            $text = $this->_currentStatusResponse();
        }

        switch ($code) {
            // 502, 'access restriction or permission denied' / service permanently unavailable.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NOT_PERMITTED:
                $this->throwError('Command not permitted / Access restriction / Permission denied', $code, $text);
                break;
            default:
                $this->throwError("Unexpected response", $code, $text);
        }
    }

    /**
     * Connect to a NNTP server.
     *
     * @param string $host The address of the NNTP-server to connect to, defaults to 'localhost'.
     * @param mixed  $encryption
     * @param int    $port The port number to connect to, defaults to 119.
     * @param int    $timeout
     *
     * @return bool on success (true when posting allowed, otherwise false).
     */
    protected function connect($host = null, $encryption = null, $port = null, $timeout = null)
    {
        if ($this->_isConnected()) {
            $this->throwError('Already connected, disconnect first!', null);
        }

        // v1.0.x API
        if (is_int($encryption)) {
            trigger_error('You are using deprecated API v1.0 in Net_NNTP_Protocol_Client: connect() !', E_USER_NOTICE);
            $port       = $encryption;
            $encryption = false;
        }

        if (is_null($host)) {
            $host = 'localhost';
        }

        // Choose transport based on encryption, and if no port is given, use default for that encryption.
        switch ($encryption) {
            case null:
            case false:
                $transport = 'tcp';
                $port      = is_null($port) ? 119 : $port;
                break;
            case 'ssl':
            case 'tls':
                $transport  = $encryption;
                $port       = is_null($port) ? 563 : $port;
                $this->_ssl = true;
                break;
            default:
                trigger_error('$encryption parameter must be either tcp, tls or ssl.', E_USER_ERROR);
        }

        if (is_null($timeout)) {
            $timeout = 15;
        }

        // Open Connection
        $R = stream_socket_client($transport . '://' . $host . ':' . $port, $errno, $errstr, $timeout);
        if ($R === false) {
            if ($this->_logger) {
                $this->_logger->notice("Connection to $transport://$host:$port failed.");
            }

            return $R;
        }

        $this->_socket = $R;

        if ($this->_logger) {
            $this->_logger->info("Connection to $transport://$host:$port has been established.");
        }

        // set a stream timeout for each operation
        stream_set_timeout($this->_socket, 240);

        // Retrieve the server's initial response.
        $response = $this->_getStatusResponse();

        switch ($response) {
            case NET_NNTP_PROTOCOL_RESPONSECODE_READY_POSTING_ALLOWED: // 200, Posting allowed
                return true;
                break;
            case NET_NNTP_PROTOCOL_RESPONSECODE_READY_POSTING_PROHIBITED: // 201, Posting NOT allowed
                if ($this->_logger) {
                    $this->_logger->info('Posting not allowed!');
                }

                return true;
                break;
            case 400:
                $this->throwError('Server refused connection', $response, $this->_currentStatusResponse());
                break;
            // 502, 'access restriction or permission denied' / service permanently unavailable.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NOT_PERMITTED:
                $this->throwError('Server refused connection', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * alias for cmdQuit().
     */
    protected function disconnect()
    {
        return $this->cmdQuit();
    }

    /**
     * Returns servers capabilities.
     *
     * @return array List of capabilities on success.
     */
    protected function cmdCapabilities()
    {
        // tell the news server we want an article.
        $response = $this->_sendCommand('CAPABILITIES');

        switch ($response) {
            // 101, Draft: 'Capability list follows'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_CAPABILITIES_FOLLOW:
                $data = $this->_getTextResponse();

                return $data;
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * @return bool True when posting allowed or false when posting is disallowed.
     */
    protected function cmdModeReader()
    {
        // tell the newsserver we want an article
        $response = $this->_sendCommand('MODE READER');

        switch ($response) {
            // 200, RFC2980: 'Hello, you can post'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_READY_POSTING_ALLOWED:
                return true;
                break;
            // 201, RFC2980: 'Hello, you can't post'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_READY_POSTING_PROHIBITED:
                if ($this->_logger) {
                    $this->_logger->info('Posting not allowed!');
                }

                return false;
                break;
            // 502, 'access restriction or permission denied' / service permanently unavailable.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NOT_PERMITTED:
                $this->throwError('Connection being closed, since service so permanently unavailable', $response,
                    $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * Disconnect from the NNTP server.
     *
     * @return bool
     */
    protected function cmdQuit()
    {
        // Tell the server to close the connection.
        $response = $this->_sendCommand('QUIT');

        switch ($response) {
            case 205: // RFC977: 'closing connection - goodbye!'
                // If socket is still open, close it.
                if ($this->_isConnected()) {
                    fclose($this->_socket);
                }

                if ($this->_logger) {
                    $this->_logger->info('Connection closed.');
                }

                return true;
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * @return bool
     */
    protected function cmdStartTLS()
    {
        $response = $this->_sendCommand('STARTTLS');

        switch ($response) {
            // RFC4642: 'continue with TLS negotiation'.
            case 382:
                $encrypted = stream_socket_enable_crypto($this->_socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                switch (true) {
                    case $encrypted === true:
                        if ($this->_logger) {
                            $this->_logger->info('TLS encryption started.');
                        }

                        return true;
                        break;
                    case $encrypted === false:
                        if ($this->_logger) {
                            $this->_logger->info('TLS encryption failed.');
                        }
                        $this->throwError('Could not initiate TLS negotiation', $response,
                            $this->_currentStatusResponse());
                        break;
                    case is_int($encrypted):
                        $this->throwError('', $response, $this->_currentStatusResponse());
                        break;
                    default:
                        $this->throwError('Internal error - unknown response from stream_socket_enable_crypto()',
                            $response, $this->_currentStatusResponse());
                }
                break;
            // RFC4642: 'can not initiate TLS negotiation'.
            case 580:
                $this->throwError('', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * Selects a news group (issue a GROUP command to the server).
     *
     * @param string $newsgroup The newsgroup name.
     *
     * @return array groupinfo on success.
     */
    protected function cmdGroup($newsgroup)
    {
        $response = $this->_sendCommand('GROUP ' . $newsgroup);

        switch ($response) {
            // 211, RFC977: 'n f l s group selected'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_GROUP_SELECTED:
                $response_arr = explode(' ', trim($this->_currentStatusResponse()));

                if ($this->_logger) {
                    $this->_logger->info('Group selected: ' . $response_arr[3]);
                }

                return [
                    'group' => $response_arr[3],
                    'first' => $response_arr[1],
                    'last'  => $response_arr[2],
                    'count' => $response_arr[0]
                ];
                break;
            // 411, RFC977: 'no such news group'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_SUCH_GROUP:
                $this->throwError('No such news group', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * @param string $newsgroup
     * @param mixed  $range
     *
     * @return array
     */
    protected function cmdListgroup($newsgroup = null, $range = null)
    {
        if (is_null($newsgroup)) {
            $command = 'LISTGROUP';
        } else {
            if (is_null($range)) {
                $command = 'LISTGROUP ' . $newsgroup;
            } else {
                $command = 'LISTGROUP ' . $newsgroup . ' ' . $range;
            }
        }

        $response = $this->_sendCommand($command);

        switch ($response) {
            // 211, RFC2980: 'list of article numbers follow'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_GROUP_SELECTED:
                $articles     = $this->_getTextResponse();
                $response_arr = explode(' ', trim($this->_currentStatusResponse()), 4);

                // If server does not return group summary in status response, return null'ed array.
                if (!is_numeric($response_arr[0]) || !is_numeric($response_arr[1]) || !is_numeric($response_arr[2]) || empty($response_arr[3])) {
                    return [
                        'group'    => null,
                        'first'    => null,
                        'last'     => null,
                        'count'    => null,
                        'articles' => $articles
                    ];
                }

                return [
                    'group'    => $response_arr[3],
                    'first'    => $response_arr[1],
                    'last'     => $response_arr[2],
                    'count'    => $response_arr[0],
                    'articles' => $articles
                ];
                break;
            // 412, RFC2980: 'Not currently in newsgroup'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_GROUP_SELECTED:
                $this->throwError('Not currently in newsgroup', $response, $this->_currentStatusResponse());
                break;
            // RFC2980: 'no permission'.
            case 502:
                $this->throwError('No permission', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * @return mixed (array) or (string) or (int).
     */
    protected function cmdLast()
    {
        $response = $this->_sendCommand('LAST');
        switch ($response) {
            /**
             * 223, RFC977: 'n a article retrieved - request text separately
             * (n = article number, a = unique article id)'.
             */
            case NET_NNTP_PROTOCOL_RESPONSECODE_ARTICLE_SELECTED:
                $response_arr = explode(' ', trim($this->_currentStatusResponse()));

                if ($this->_logger) {
                    $this->_logger->info('Selected previous article: ' . $response_arr[0] . ' - ' . $response_arr[1]);
                }

                return [$response_arr[0], (string)$response_arr[1]];
                break;
            // 412, RFC977: 'no newsgroup selected'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_GROUP_SELECTED:
                $this->throwError('No newsgroup has been selected', $response, $this->_currentStatusResponse());
                break;
            // 420, RFC977: 'no current article has been selected'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_ARTICLE_SELECTED:
                $this->throwError('No current article has been selected', $response, $this->_currentStatusResponse());
                break;
            // 422, RFC977: 'no previous article in this group'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_PREVIOUS_ARTICLE:
                $this->throwError('No previous article in this group', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * @return mixed (array) or (string) or (int).
     */
    protected function cmdNext()
    {
        $response = $this->_sendCommand('NEXT');

        switch ($response) {
            /**
             * 223, RFC977: 'n a article retrieved - request text separately
             * (n = article number, a = unique article id)'.
             */
            case NET_NNTP_PROTOCOL_RESPONSECODE_ARTICLE_SELECTED:
                $response_arr = explode(' ', trim($this->_currentStatusResponse()));

                if ($this->_logger) {
                    $this->_logger->info('Selected previous article: ' . $response_arr[0] . ' - ' . $response_arr[1]);
                }

                return [$response_arr[0], (string)$response_arr[1]];
                break;
            // 412, RFC977: 'no newsgroup selected'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_GROUP_SELECTED:
                $this->throwError('No newsgroup has been selected', $response, $this->_currentStatusResponse());
                break;
            // 420, RFC977: 'no current article has been selected'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_ARTICLE_SELECTED:
                $this->throwError('No current article has been selected', $response, $this->_currentStatusResponse());
                break;
            // 421, RFC977: 'no next article in this group'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_NEXT_ARTICLE:
                $this->throwError('No next article in this group', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * Get an article from the currently open connection.
     *
     * @param mixed $article Either a message-id or a message-number of the article to fetch.
     *                       If null or '', then use current article.
     *
     * @return array Article on success.
     */
    protected function cmdArticle($article = null)
    {
        if (is_null($article)) {
            $command = 'ARTICLE';
        } else {
            $command = 'ARTICLE ' . $article;
        }

        // tell the newsserver we want an article
        $response = $this->_sendCommand($command);

        switch ($response) {
            // 220, RFC977: 'n <a> article retrieved - head and body follow (n = article number, <a> = message-id)'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_ARTICLE_FOLLOWS:
                $data = $this->_getTextResponse();

                if ($this->_logger) {
                    $this->_logger->info(($article == null ? 'Fetched current article' : 'Fetched article: ' . $article));
                }

                return $data;
                break;
            // 412, RFC977: 'no newsgroup has been selected'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_GROUP_SELECTED:
                $this->throwError('No newsgroup has been selected', $response, $this->_currentStatusResponse());
                break;
            // 420, RFC977: 'no current article has been selected'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_ARTICLE_SELECTED:
                $this->throwError('No current article has been selected', $response, $this->_currentStatusResponse());
                break;
            // 423, RFC977: 'no such article number in this group'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_SUCH_ARTICLE_NUMBER:
                $this->throwError('No such article number in this group', $response, $this->_currentStatusResponse());
                break;
            // 430, RFC977: 'no such article found'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_SUCH_ARTICLE_ID:
                $this->throwError('No such article found', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * Get the headers of an article from the currently open connection.
     *
     * @param mixed $article Either a message-id or a message-number of the article to fetch the headers from. If null
     *                       or '', then use current article.
     *
     * @return array Headers on success.
     */
    protected function cmdHead($article = null)
    {
        if (is_null($article)) {
            $command = 'HEAD';
        } else {
            $command = 'HEAD ' . $article;
        }

        // tell the newsserver we want the header of an article.
        $response = $this->_sendCommand($command);

        switch ($response) {
            // 221, RFC977: 'n <a> article retrieved - head follows'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_HEAD_FOLLOWS:
                $data = $this->_getTextResponse();

                if ($this->_logger) {
                    $this->_logger->info(($article == null ? 'Fetched current article header' : 'Fetched article header for article: ' . $article));
                }

                return $data;
                break;
            // 412, RFC977: 'no newsgroup has been selected'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_GROUP_SELECTED:
                $this->throwError('No newsgroup has been selected', $response, $this->_currentStatusResponse());
                break;
            // 420, RFC977: 'no current article has been selected'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_ARTICLE_SELECTED:
                $this->throwError('No current article has been selected', $response, $this->_currentStatusResponse());
                break;
            // 423, RFC977: 'no such article number in this group'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_SUCH_ARTICLE_NUMBER:
                $this->throwError('No such article number in this group', $response, $this->_currentStatusResponse());
                break;
            // 430, RFC977: 'no such article found'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_SUCH_ARTICLE_ID:
                $this->throwError('No such article found', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * Get the body of an article from the currently open connection.
     *
     * @param mixed $article Either a message-id or a message-number of the article to fetch the body from. If null or
     *                       '', then use current article.
     *
     * @return array Body on success.
     */
    protected function cmdBody($article = null)
    {
        if (is_null($article)) {
            $command = 'BODY';
        } else {
            $command = 'BODY ' . $article;
        }

        // tell the newsserver we want the body of an article.
        $response = $this->_sendCommand($command);

        switch ($response) {
            // 222, RFC977: 'n <a> article retrieved - body follows'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_BODY_FOLLOWS:
                $data = $this->_getTextResponse();

                if ($this->_logger) {
                    $this->_logger->info(($article == null ? 'Fetched current article body' : 'Fetched article body for article: ' . $article));
                }

                return $data;
                break;
            // 412, RFC977: 'no newsgroup has been selected'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_GROUP_SELECTED:
                $this->throwError('No newsgroup has been selected', $response, $this->_currentStatusResponse());
                break;
            // 420, RFC977: 'no current article has been selected'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_ARTICLE_SELECTED:
                $this->throwError('No current article has been selected', $response, $this->_currentStatusResponse());
                break;
            // 423, RFC977: 'no such article number in this group'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_SUCH_ARTICLE_NUMBER:
                $this->throwError('No such article number in this group', $response, $this->_currentStatusResponse());
                break;
            // 430, RFC977: 'no such article found'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_SUCH_ARTICLE_ID:
                $this->throwError('No such article found', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * @param mixed $article
     *
     * @return mixed (array) or (string) or (int).
     */
    protected function cmdStat($article = null)
    {
        if (is_null($article)) {
            $command = 'STAT';
        } else {
            $command = 'STAT ' . $article;
        }

        // tell the newsserver we want an article.
        $response = $this->_sendCommand($command);

        switch ($response) {
            // 223, RFC977: 'n <a> article retrieved - request text separately' (actually not documented, but copied from the ARTICLE command).
            case NET_NNTP_PROTOCOL_RESPONSECODE_ARTICLE_SELECTED:
                $response_arr = explode(' ', trim($this->_currentStatusResponse()));

                if ($this->_logger) {
                    $this->_logger->info('Selected article: ' . $response_arr[0] . ' - ' . $response_arr[1]);
                }

                return [$response_arr[0], (string)$response_arr[1]];
                break;
            // 412, RFC977: 'no newsgroup has been selected' (actually not documented, but copied from the ARTICLE command).
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_GROUP_SELECTED:
                $this->throwError('No newsgroup has been selected', $response, $this->_currentStatusResponse());
                break;
            // 423, RFC977: 'no such article number in this group' (actually not documented, but copied from the ARTICLE command).
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_SUCH_ARTICLE_NUMBER:
                $this->throwError('No such article number in this group', $response, $this->_currentStatusResponse());
                break;
            // 430, RFC977: 'no such article found' (actually not documented, but copied from the ARTICLE command).
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_SUCH_ARTICLE_ID:
                $this->throwError('No such article found', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }



    /* Article posting */

    /**
     * Post an article to a newsgroup.
     *
     * @return mixed (bool) true on success.
     */
    function cmdPost()
    {
        // tell the newsserver we want to post an article.
        $response = $this->_sendCommand('POST');

        switch ($response) {
            // 340, RFC977: 'send article to be posted. End with <CR-LF>.<CR-LF>'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_POSTING_SEND:
                return true;
                break;
            // 440, RFC977: 'posting not allowed'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_POSTING_PROHIBITED:
                $this->throwError('Posting not allowed', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * Post an article to a newsgroup.
     *
     * @param string|array $article
     *
     * @return bool
     */
    protected function cmdPost2($article)
    {
        // should be presented in the format specified by RFC850.
        $this->_sendArticle($article);

        // Retrieve server's response.
        $response = $this->_getStatusResponse();

        switch ($response) {
            // 240, RFC977: 'article posted ok'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_POSTING_SUCCESS:
                return true;
                break;
            // 441, RFC977: 'posting failed'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_POSTING_FAILURE:
                $this->throwError('Posting failed', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * @param string $id
     *
     * @return bool
     */
    protected function cmdIhave($id)
    {
        // tell the newsserver we want to post an article
        $response = $this->_sendCommand('IHAVE ' . $id);

        switch ($response) {
            case NET_NNTP_PROTOCOL_RESPONSECODE_TRANSFER_SEND: // 335
                return true;
                break;
            case NET_NNTP_PROTOCOL_RESPONSECODE_TRANSFER_UNWANTED: // 435
                $this->throwError('Article not wanted', $response, $this->_currentStatusResponse());
                break;
            case NET_NNTP_PROTOCOL_RESPONSECODE_TRANSFER_FAILURE: // 436
                $this->throwError('Transfer not possible; try again later', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * @param string|array $article
     *
     * @return bool
     */
    protected function cmdIhave2($article)
    {
        // should be presented in the format specified by RFC850.
        $this->_sendArticle($article);

        // Retrieve server's response.
        $response = $this->_getStatusResponse();

        switch ($response) {
            // 235
            case NET_NNTP_PROTOCOL_RESPONSECODE_TRANSFER_SUCCESS:
                return true;
                break;
            // 436
            case NET_NNTP_PROTOCOL_RESPONSECODE_TRANSFER_FAILURE:
                $this->throwError('Transfer not possible; try again later', $response, $this->_currentStatusResponse());
                break;
            // 437
            case NET_NNTP_PROTOCOL_RESPONSECODE_TRANSFER_REJECTED:
                $this->throwError('Transfer rejected; do not retry', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * Get the date from the news server format of returned date.
     *
     * @return mixed (string) 'YYYYMMDDhhmmss' / (int) timestamp.
     */
    protected function cmdDate()
    {
        $response = $this->_sendCommand('DATE');

        switch ($response) {
            // 111, RFC2980: 'YYYYMMDDhhmmss'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_SERVER_DATE:
                return $this->_currentStatusResponse();
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * Returns the server's help text.
     *
     * @return array help text on success.
     */
    protected function cmdHelp()
    {
        // tell the newsserver we want an article
        $response = $this->_sendCommand('HELP');

        switch ($response) {
            case NET_NNTP_PROTOCOL_RESPONSECODE_HELP_FOLLOWS: // 100
                $data = $this->_getTextResponse();

                return $data;
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * Fetches a list of all newsgroups created since a specified date.
     *
     * @param int    $time          Last time you checked for groups (timestamp).
     * @param string $distributions Deprecated in rfc draft.
     *
     * @return array Nested array with information about existing newsgroups.
     */
    protected function cmdNewgroups($time, $distributions = null)
    {
        $date = gmdate('ymd His', $time);

        if (is_null($distributions)) {
            $command = 'NEWGROUPS ' . $date . ' GMT';
        } else {
            $command = 'NEWGROUPS ' . $date . ' GMT <' . $distributions . '>';
        }

        $response = $this->_sendCommand($command);

        switch ($response) {
            // 231, REF977: 'list of new newsgroups follows'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NEW_GROUPS_FOLLOW:
                $data = $this->_getTextResponse();

                $groups = [];
                foreach ($data as $line) {
                    $arr = explode(' ', trim($line));

                    $group = [
                        'group'   => $arr[0],
                        'last'    => $arr[1],
                        'first'   => $arr[2],
                        'posting' => $arr[3]
                    ];

                    $groups[$group['group']] = $group;
                }

                return $groups;

            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * @param int             $time
     * @param string|string[] $newsgroups
     * @param string|string[] $distribution
     *
     * @return array
     */
    protected function cmdNewnews($time, $newsgroups, $distribution = null)
    {
        $date = gmdate('ymd His', $time);

        if (is_array($newsgroups)) {
            $newsgroups = implode(',', $newsgroups);
        }

        if (is_null($distribution)) {
            $command = 'NEWNEWS ' . $newsgroups . ' ' . $date . ' GMT';
        } else {
            if (is_array($distribution)) {
                $distribution = implode(',', $distribution);
            }

            $command = 'NEWNEWS ' . $newsgroups . ' ' . $date . ' GMT <' . $distribution . '>';
        }

        $response = $this->_sendCommand($command);

        switch ($response) {
            // 230, RFC977: 'list of new articles by message-id follows'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NEW_ARTICLES_FOLLOW:
                $messages = [];
                foreach ($this->_getTextResponse() as $line) {
                    $messages[] = $line;
                }

                return $messages;
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * Fetches a list of all avaible newsgroups.
     *
     * @return array Nested array with information about existing newsgroups.
     */
    protected function cmdList()
    {
        $response = $this->_sendCommand('LIST');

        switch ($response) {
            // 215, RFC977: 'list of newsgroups follows'
            case NET_NNTP_PROTOCOL_RESPONSECODE_GROUPS_FOLLOW:
                $data   = $this->_getTextResponse();
                $groups = [];
                foreach ($data as $line) {
                    $arr = explode(' ', trim($line));

                    $group = [
                        'group'   => $arr[0],
                        'last'    => $arr[1],
                        'first'   => $arr[2],
                        'posting' => $arr[3]
                    ];

                    $groups[$group['group']] = $group;
                }

                return $groups;
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * Fetches a list of all avaible newsgroups.
     *
     * @param string $wildmat
     *
     * @return array Nested array with information about existing newsgroups.
     */
    protected function cmdListActive($wildmat = null)
    {
        if (is_null($wildmat)) {
            $command = 'LIST ACTIVE';
        } else {
            $command = 'LIST ACTIVE ' . $wildmat;
        }

        $response = $this->_sendCommand($command);

        switch ($response) {
            case NET_NNTP_PROTOCOL_RESPONSECODE_GROUPS_FOLLOW: // 215, RFC977: 'list of newsgroups follows'
                $data = $this->_getTextResponse();

                $groups = [];
                foreach ($data as $line) {
                    $arr = explode(' ', trim($line));

                    $group = [
                        'group'   => $arr[0],
                        'last'    => $arr[1],
                        'first'   => $arr[2],
                        'posting' => $arr[3]
                    ];

                    $groups[$group['group']] = $group;
                }

                if ($this->_logger) {
                    $this->_logger->info('Fetched list of available groups');
                }

                return $groups;
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * Fetches a list of (all) avaible newsgroup descriptions.
     *
     * @param string $wildmat Wildmat of the groups, that is to be listed, defaults to null.
     *
     * @return array nested array with description of existing newsgroups.
     */
    protected function cmdListNewsgroups($wildmat = null)
    {
        if (is_null($wildmat)) {
            $command = 'LIST NEWSGROUPS';
        } else {
            $command = 'LIST NEWSGROUPS ' . $wildmat;
        }

        $response = $this->_sendCommand($command);

        switch ($response) {
            // 215, RFC2980: 'information follows'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_GROUPS_FOLLOW:
                $data = $this->_getTextResponse();

                $groups = [];

                foreach ($data as $line) {
                    if (preg_match("/^(\S+)\s+(.*)$/", ltrim($line), $matches)) {
                        $groups[$matches[1]] = (string)$matches[2];
                    } else {
                        if ($this->_logger) {
                            $this->_logger->warning("Recieved non-standard line: '$line'");
                        }
                    }
                }

                if ($this->_logger) {
                    $this->_logger->info('Fetched group descriptions');
                }

                return $groups;
                break;
            // RFC2980: 'program error, function not performed'.
            case 503:
                $this->throwError('Internal server error, function not performed', $response,
                    $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * Fetch message header from message number $first until $last
     * The format of the returned array is:
     * $messages[][header_name].
     *
     * @param string $range Articles to fetch.
     *
     * @return array Nested array of message and their headers.
     */
    protected function cmdOver($range = null)
    {
        if (is_null($range)) {
            $command = 'OVER';
        } else {
            $command = 'OVER ' . $range;
        }

        $response = $this->_sendCommand($command);

        switch ($response) {
            // 224, RFC2980: 'Overview information follows'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_OVERVIEW_FOLLOWS:
                $data = $this->_getTextResponse();

                foreach ($data as $key => $value) {
                    $data[$key] = explode("\t", trim($value));
                }

                if ($this->_logger) {
                    $this->_logger->info('Fetched overview ' . ($range == null ? 'for current article' : 'for range: ' . $range));
                }

                return $data;
                break;
            // 412, RFC2980: 'No news group current selected'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_GROUP_SELECTED:
                $this->throwError('No news group current selected', $response, $this->_currentStatusResponse());
                break;
            // 420, RFC2980: 'No article(s) selected'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_ARTICLE_SELECTED:
                $this->throwError('No article(s) selected', $response, $this->_currentStatusResponse());
                break;
            // 423:, Draft27: 'No articles in that range'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_SUCH_ARTICLE_NUMBER:
                $this->throwError('No articles in that range', $response, $this->_currentStatusResponse());
                break;
            // RFC2980: 'no permission'.
            case 502:
                $this->throwError('No permission', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * Fetch message header from message number $first until $last
     * The format of the returned array is:
     * $messages[message_id][header_name].
     *
     * @param string $range Articles to fetch.
     *
     * @return array Nested array of message and their headers.
     */
    protected function cmdXOver($range = null)
    {
        // deprecated API (the code _is_ still in alpha state)
        if (func_num_args() > 1) {
            die('The second parameter in cmdXOver() has been deprecated! Use x-y instead...');
        }

        if (is_null($range)) {
            $command = 'XOVER';
        } else {
            $command = 'XOVER ' . $range;
        }

        $response = $this->_sendCommand($command);

        switch ($response) {
            case NET_NNTP_PROTOCOL_RESPONSECODE_OVERVIEW_FOLLOWS: // 224, RFC2980: 'Overview information follows'
                $data = $this->_getTextResponse();

                foreach ($data as $key => $value) {
                    $data[$key] = explode("\t", trim($value));
                }

                if ($this->_logger) {
                    $this->_logger->info('Fetched overview ' . ($range == null ? 'for current article' : 'for range: ' . $range));
                }

                return $data;
                break;
            // 412, RFC2980: 'No news group current selected'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_GROUP_SELECTED:
                $this->throwError('No news group current selected', $response, $this->_currentStatusResponse());
                break;
            // 420, RFC2980: 'No article(s) selected'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_ARTICLE_SELECTED:
                $this->throwError('No article(s) selected', $response, $this->_currentStatusResponse());
                break;
            // RFC2980: 'no permission'.
            case 502:
                $this->throwError('No permission', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /*
     * Based on code from http://wonko.com/software/yenc/, but
     * simplified because XZVER and the likes don't implement
     * yenc properly.
     */
    private function yencDecode($string, $destination = "")
    {
        $encoded = [];
        $header  = [];
        $decoded = '';

        // Extract the yEnc string itself.
        preg_match("/^(=ybegin.*=yend[^$]*)$/ims", $string, $encoded);
        $encoded = $encoded[1];

        // Extract the filesize and filename from the yEnc header.
        preg_match("/^=ybegin.*size=([^ $]+).*name=([^\\r\\n]+)/im", $encoded, $header);
        $filesize = $header[1];
        $filename = $header[2];

        // Remove the header and footer from the string before parsing it.
        $encoded = preg_replace("/(^=ybegin.*\\r\\n)/im", "", $encoded, 1);
        $encoded = preg_replace("/(^=yend.*)/im", "", $encoded, 1);

        // Remove line breaks and whitespace from the string.
        $encoded = trim(str_replace("\r\n", "", $encoded));

        // Decode.
        $strLength = strlen($encoded);
        for ($i = 0; $i < $strLength; $i++) {
            $c = $encoded[$i];

            if ($c == '=') {
                $i++;
                $decoded .= chr((ord($encoded[$i]) - 64) - 42);
            } else {
                $decoded .= chr(ord($c) - 42);
            }
        }

        // Make sure the decoded filesize is the same as the size specified in the header.
        if (strlen($decoded) != $filesize) {
            throw new Exception("Filesize in yEnc header en filesize found do not match up");
        }

        return $decoded;
    }

    /**
     * Fetch message header from message number $first until $last
     * The format of the returned array is:
     * $messages[message_id][header_name]
     *
     * @param string $range Articles to fetch.
     *
     * @return array Nested array of message and their headers.
     */
    protected function cmdXZver($range = null)
    {
        if (is_null($range)) {
            $command = 'XZVER';
        } else {
            $command = 'XZVER ' . $range;
        }

        $response = $this->_sendCommand($command);

        switch ($response) {
            // 224, RFC2980: 'Overview information follows'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_OVERVIEW_FOLLOWS:
                $data = $this->_getCompressedResponse();
                foreach ($data as $key => $value) {
                    $data[$key] = explode("\t", trim($value));
                }

                if ($this->_logger) {
                    $this->_logger->info('Fetched overview ' . ($range == null ? 'for current article' : 'for range: ' . $range));
                }

                return $data;
                break;
            // 412, RFC2980: 'No news group current selected'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_GROUP_SELECTED:
                $this->throwError('No news group current selected', $response, $this->_currentStatusResponse());
                break;
            // 420, RFC2980: 'No article(s) selected'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_ARTICLE_SELECTED:
                $this->throwError('No article(s) selected', $response, $this->_currentStatusResponse());
                break;
            // RFC2980: 'no permission'.
            case 502:
                $this->throwError('No permission', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * Returns a list of avaible headers which are send from newsserver to client for every news message.
     *
     * @return array Header names.
     */
    protected function cmdListOverviewFmt()
    {
        $response = $this->_sendCommand('LIST OVERVIEW.FMT');

        switch ($response) {
            // 215, RFC2980: 'information follows'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_GROUPS_FOLLOW:
                $data = $this->_getTextResponse();

                $format = [];

                foreach ($data as $line) {
                    // Check if postfixed by ':full' (case-insensitive).
                    if (0 == strcasecmp(substr($line, -5, 5), ':full')) {
                        // ':full' is _not_ included in tag, but value set to true.
                        $format[substr($line, 0, -5)] = true;
                    } else {
                        // ':' is _not_ included in tag; value set to false.
                        $format[substr($line, 0, -1)] = false;
                    }
                }

                if ($this->_logger) {
                    $this->_logger->info('Fetched overview format');
                }

                return $format;
                break;
            // RFC2980: 'program error, function not performed'.
            case 503:
                $this->throwError('Internal server error, function not performed', $response,
                    $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * The format of the returned array is:
     * $messages[message_id].
     *
     * @param string $field
     * @param string $range Articles to fetch.
     *
     * @return array Nested array of message and their headers.
     */
    protected function cmdXHdr($field, $range = null)
    {
        if (is_null($range)) {
            $command = 'XHDR ' . $field;
        } else {
            $command = 'XHDR ' . $field . ' ' . $range;
        }

        $response = $this->_sendCommand($command);

        switch ($response) {
            // 221, RFC2980: 'Header follows'
            case 221:
                $data = $this->_getTextResponse();

                $return = [];
                foreach ($data as $line) {
                    $line             = explode(' ', trim($line), 2);
                    $return[$line[0]] = $line[1];
                }

                return $return;
                break;
            // 412, RFC2980: 'No news group current selected'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_GROUP_SELECTED:
                $this->throwError('No news group current selected', $response, $this->_currentStatusResponse());
                break;
            // 420, RFC2980: 'No current article selected'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_ARTICLE_SELECTED:
                $this->throwError('No current article selected', $response, $this->_currentStatusResponse());
                break;
            // 430, RFC2980: 'No such article'.
            case 430:
                $this->throwError('No such article', $response, $this->_currentStatusResponse());
                break;
            // RFC2980: 'no permission'.
            case 502:
                $this->throwError('No permission', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * Fetches a list of (all) avaible newsgroup descriptions.
     * Deprecated as of RFC2980.
     *
     * @param string $wildmat Wildmat of the groups, that is to be listed, defaults to '*'.
     *
     * @return array Nested array with description of existing newsgroups.
     */
    protected function cmdXGTitle($wildmat = '*')
    {
        $response = $this->_sendCommand('XGTITLE ' . $wildmat);

        switch ($response) {
            // RFC2980: 'list of groups and descriptions follows'.
            case 282:
                $data = $this->_getTextResponse();

                $groups = [];

                foreach ($data as $line) {
                    preg_match("/^(.*?)\s(.*?$)/", trim($line), $matches);
                    $groups[$matches[1]] = (string)$matches[2];
                }

                return $groups;
                break;

            // RFC2980: 'Groups and descriptions unavailable'.
            case 481:
                $this->throwError('Groups and descriptions unavailable', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * Fetch message references from message number $first to $last.
     *
     * @param string $range Articles to fetch.
     *
     * @return array Message references.
     */
    protected function cmdXROver($range = null)
    {
        // Warn about deprecated API (the code _is_ still in alpha state)
        if (func_num_args() > 1) {
            die('The second parameter in cmdXROver() has been deprecated! Use x-y instead...');
        }

        if (is_null($range)) {
            $command = 'XROVER';
        } else {
            $command = 'XROVER ' . $range;
        }

        $response = $this->_sendCommand($command);

        switch ($response) {
            // 224, RFC2980: 'Overview information follows'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_OVERVIEW_FOLLOWS:
                $data = $this->_getTextResponse();

                $return = [];
                foreach ($data as $line) {
                    $line             = explode(' ', trim($line), 2);
                    $return[$line[0]] = $line[1];
                }

                return $return;
                break;
            // 412, RFC2980: 'No news group current selected'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_GROUP_SELECTED:
                $this->throwError('No news group current selected', $response, $this->_currentStatusResponse());
                break;
            // 420, RFC2980: 'No article(s) selected'.
            case NET_NNTP_PROTOCOL_RESPONSECODE_NO_ARTICLE_SELECTED:
                $this->throwError('No article(s) selected', $response, $this->_currentStatusResponse());
                break;
            // RFC2980: 'no permission'.
            case 502:
                $this->throwError('No permission', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * @param string $field
     * @param string $range
     * @param mixed  $wildmat
     *
     * @return array Nested array of message and their headers.
     */
    protected function cmdXPat($field, $range, $wildmat)
    {
        if (is_array($wildmat)) {
            $wildmat = implode(' ', $wildmat);
        }

        $response = $this->_sendCommand('XPAT ' . $field . ' ' . $range . ' ' . $wildmat);

        switch ($response) {
            // 221, RFC2980: 'Header follows'.
            case 221:
                $data = $this->_getTextResponse();

                $return = [];
                foreach ($data as $line) {
                    $line             = explode(' ', trim($line), 2);
                    $return[$line[0]] = $line[1];
                }

                return $return;
                break;
            // 430, RFC2980: 'No such article'.
            case 430:
                $this->throwError('No current article selected', $response, $this->_currentStatusResponse());
                break;
            // RFC2980: 'no permission'.
            case 502:
                $this->throwError('No permission', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * Authenticate using 'original' method.
     *
     * @param string $user The username to authenticate as.
     * @param string $pass The password to authenticate with.
     *
     * @return bool
     */
    protected function cmdAuthinfo($user, $pass)
    {
        // Send the username.
        $response = $this->_sendCommand('AUTHINFO user ' . $user);

        // Send the password, if the server asks.
        if (($response == 381) && ($pass !== null)) {
            // Send the password
            $response = $this->_sendCommand('AUTHINFO pass ' . $pass);
        }

        switch ($response) {
            // RFC2980: 'Authentication accepted'.
            case 281:
                if ($this->_logger) {
                    $this->_logger->info("Authenticated (as user '$user')");
                }

                return true;
                break;
            // RFC2980: 'More authentication information required'.
            case 381:
                $this->throwError('Authentication uncompleted', $response, $this->_currentStatusResponse());
                break;
            // RFC2980: 'Authentication rejected'.
            case 482:
                $this->throwError('Authentication rejected', $response, $this->_currentStatusResponse());
                break;
            // RFC2980: 'No permission'.
            case 502:
                $this->throwError('Authentication rejected', $response, $this->_currentStatusResponse());
                break;
            default:
                return $this->_handleUnexpectedResponse($response);
        }
    }

    /**
     * Authenticate using 'simple' method.
     *
     * @param string $user The username to authenticate as.
     * @param string $pass The password to authenticate with.
     *
     * @return bool
     */
    protected function cmdAuthinfoSimple($user, $pass)
    {
        $this->throwError("The auth mode: 'simple' is has not been implemented yet", null);
    }

    /**
     * Authenticate using 'generic' method.
     *
     * @param string $user The username to authenticate as.
     * @param string $pass The password to authenticate with.
     *
     * @return bool
     */
    protected function cmdAuthinfoGeneric($user, $pass)
    {
        $this->throwError("The auth mode: 'generic' is has not been implemented yet", null);
    }

    /**
     * Test whether we are connected or not.
     *
     * @return bool
     */
    protected function _isConnected()
    {
        return (is_resource($this->_socket) && (!feof($this->_socket)));
    }

    /**
     * @param        $detail
     * @param int    $code
     * @param string $response
     */
    function throwError($detail, $code = -1, $response = "")
    {
        throw new NntpException($detail, $code, $response);
    }
}
