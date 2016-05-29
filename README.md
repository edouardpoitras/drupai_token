Drupai Token
============

A Drupai plugin that allows for token replacement of text/voice queries. Whenever the text 'token <number>' is found, this plugin will replace that part of the text with the token value found for that ID.

Often used for things like names, places, or words that your speech handler simply can't get right. Could also come in handy if you want to 'save' a really long bit of text for re-use.

Will modify the client provided text in the AFTER_READY_TEXT event, before the PROCESS_TEXT event gets dispatched so that all plugins have the token-replaced text. This means you must be careful when deleting or getting tokens, as your query of say 'delete token 6' may be converted to 'delete <token 5 value>' before it gets processed by this plugin's PROCESS_TEXT.  All that to say is if you want to delete/get a token value outside of the web UI, you should say 'token number 3' instead of 'token 3'

You can list the existing tokens in one of two ways:
 - Via client input with text/audio that contains the work 'token' and a few other keywords like 'list' or 'enumerate'
 - By browsing to /drupai/tokens

  Example: 'list tokens', 'what are the available tokens', 'tokens', etc

You can create new tokens in one of two ways:
 - Via client input with text/audio that contains both the words 'token' and 'create' or 'new'
 - By browsing to /drupai/tokens and clicking on 'Add Token'

  Example: 'create new token', 'new token with id 8'

You can edit tokens by browsing to /drupai/tokens and using the edit link.

You can delete tokens in one of two ways:
 - Via client input with text/audio that contains both the word 'token' and 'delete' or 'remove'
 - By browsing to /drupai/tokens and using the delete link

  Example: 'delete token with id 3', 'the token with id 8 sucks, delete it please'
