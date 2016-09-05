<?php

/**
 * 'Server ready - posting allowed' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_READY_POSTING_ALLOWED = 200;

/**
 * 'Server ready - no posting allowed' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_READY_POSTING_PROHIBITED = 201;

/**
 * 'Closing connection - goodbye!' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_DISCONNECTING_REQUESTED = 205;

/**
 * 'Service discontinued' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_DISCONNECTING_FORCED = 400;

/**
 * 'Slave status noted' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_SLAVE_RECOGNIZED = 202;

/**
 * 'Command not recognized' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_UNKNOWN_COMMAND = 500;

/**
 * 'Command syntax error' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_SYNTAX_ERROR = 501;

/**
 * 'Access restriction or permission denied' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_NOT_PERMITTED = 502;

/**
 * 'Program fault - command not performed' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_NOT_SUPPORTED = 503;

/**
 * 'Group selected' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_GROUP_SELECTED = 211;

/**
 * 'No such news group' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_NO_SUCH_GROUP = 411;

/**
 * 'Article retrieved - head and body follow' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_ARTICLE_FOLLOWS = 220;

/**
 * 'Article retrieved - head follows' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_HEAD_FOLLOWS = 221;

/**
 * 'Article retrieved - body follows' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_BODY_FOLLOWS = 222;

/**
 * 'Article retrieved - request text separately' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_ARTICLE_SELECTED = 223;

/**
 * 'No newsgroup has been selected' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_NO_GROUP_SELECTED = 412;

/**
 * 'No current article has been selected' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_NO_ARTICLE_SELECTED = 420;

/**
 * 'No next article in this group' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_NO_NEXT_ARTICLE = 421;

/**
 * 'No previous article in this group' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_NO_PREVIOUS_ARTICLE = 422;

/**
 * 'No such article number in this group' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_NO_SUCH_ARTICLE_NUMBER = 423;

/**
 * 'No such article found' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_NO_SUCH_ARTICLE_ID = 430;

/**
 * 'Send article to be transferred' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_TRANSFER_SEND = 335;

/**
 * 'Article transferred ok' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_TRANSFER_SUCCESS = 235;

/**
 * 'Article not wanted - do not send it' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_TRANSFER_UNWANTED = 435;

/**
 * 'Transfer failed - try again later' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_TRANSFER_FAILURE = 436;

/**
 * 'Article rejected - do not try again' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_TRANSFER_REJECTED = 437;

/**
 * 'Send article to be posted' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_POSTING_SEND = 340;

/**
 * 'Article posted ok' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_POSTING_SUCCESS = 240;

/**
 * 'Posting not allowed' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_POSTING_PROHIBITED = 440;

/**
 * 'Posting failed' (RFC977).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_POSTING_FAILURE = 441;

/**
 * 'Authorization required for this command' (RFC2980).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_AUTHORIZATION_REQUIRED = 450;

/**
 * 'Continue with authorization sequence' (RFC2980).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_AUTHORIZATION_CONTINUE = 350;

/**
 * 'Authorization accepted' (RFC2980).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_AUTHORIZATION_ACCEPTED = 250;

/**
 * 'Authorization rejected' (RFC2980).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_AUTHORIZATION_REJECTED = 452;

/**
 * 'Authentication required' (RFC2980).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_AUTHENTICATION_REQUIRED = 480;

/**
 * 'More authentication information required' (RFC2980).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_AUTHENTICATION_CONTINUE = 381;

/**
 * 'Authentication accepted' (RFC2980).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_AUTHENTICATION_ACCEPTED = 281;

/**
 * 'Authentication rejected' (RFC2980).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_AUTHENTICATION_REJECTED = 482;

/**
 * 'Help text follows' (Draft).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_HELP_FOLLOWS = 100;

/**
 * 'Capabilities list follows' (Draft).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_CAPABILITIES_FOLLOW = 101;

/**
 * 'Server date and time' (Draft).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_SERVER_DATE = 111;

/**
 * 'Information follows' (Draft).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_GROUPS_FOLLOW = 215;

/**
 * 'Overview information follows' (Draft)
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_OVERVIEW_FOLLOWS = 224;

/**
 * 'Headers follow' (Draft).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_HEADERS_FOLLOW = 225;

/**
 * 'List of new articles follows' (Draft).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_NEW_ARTICLES_FOLLOW = 230;

/**
 * 'List of new newsgroups follows' (Draft).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_NEW_GROUPS_FOLLOW = 231;

/**
 * 'The server is in the wrong mode; the indicated capability should be used to change the mode' (Draft).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_WRONG_MODE = 401;

/**
 * 'Internal fault or problem preventing action being taken' (Draft).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_INTERNAL_FAULT = 403;

/**
 * 'Command unavailable until suitable privacy has been arranged' (Draft)
 * (the client must negotiate appropriate privacy protection on the connection.
 * This will involve the use of a privacy extension such as [NNTP-TLS].).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_ENCRYPTION_REQUIRED = 483;

/**
 * 'Error in base64-encoding [RFC3548] of an argument' (Draft).
 */
const NET_NNTP_PROTOCOL_RESPONSECODE_BASE64_ENCODING_ERROR = 504;
