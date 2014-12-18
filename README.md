ODKParser
=========

ODKParser is a parser created to extract form elements from ODK xml and save them into database tables.

There are few tables in use. Form, form fieldset and form element table.

Parser initially work with Zend Framework 2 and Doctrine 2 and needs to receive service manager instance in constructor.

Usage
=====

Parser class should be used with Zend Framework 2 "**use**" statement as **use ODKParser\ODKParser;**

Also, getting and ODKParser object works as **$odk = new ODKParser($this->getServiceLocator());**

Future
======

It is implemented in purpose of unique .xml form standardized by XMLForm so maybe it would need to be improved and extendend to support other formats of XMLForm convention.

Hope you'll enjoy!
