WordPress Package Parser
========================

A PHP library for parsing WordPress plugin and theme metadata. Point it at a ZIP package and it will:

- Tell you whether it contains a plugin or a theme.
- Give you the metadata from the comment header (Version, Description, Author URI, etc).
- Parse readme.txt into a list of headers and sections.
- Convert readme.txt contents from Markdown to HTML (optional).

Basic usage
-----------

Extract plugin metadata:

```
require 'wp-extension-meta/extension-meta.php';
$package = WshWordPressPackageParser::parsePackage('sample-plugin.zip', true);
print_r($package);
```

Sample output:

```
Array
(
    [header] => Array
        (
            [Name] => Plugin Name
            [PluginURI] => http://example.com/
            [Version] => 1.7
            [Description] => This plugin does stuff.
            [Author] => Yahnis Elsts
            [AuthorURI] => http://w-shadow.com/
            [TextDomain] => sample-plugin
            [DomainPath] => 
            [Network] => 
            [Title] => Plugin Name
        )

    [readme] => Array
        (
            [name] => Plugin Name
            [contributors] => Array
                (
                    [0] => whiteshadow
                )

            [tags] => Array
                (
                    [0] => sample
                    [1] => tag
                    [2] => stuff
                )

            [requires] => 3.2
            [tested] => 3.5.1
            [stable] => 1.7
            [short_description] => This is the short description from the readme. 
            [sections] => Array
                (
                    [Description] => This is the <em>Description</em> section of the readme.
                    [Installation] => ...
                    [Changelog] => ...
                )
        )
    [pluginFile] => sample-plugin/sample-plugin.php
    [stylesheet] => 
    [type] => plugin
)
```

Requirements
------------
PHP 5.2. 

Credits
-------
Partially based on plugin header parsing code from the WordPress core.
