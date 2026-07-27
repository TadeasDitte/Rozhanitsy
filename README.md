# Rozhanitsy

Automated DB thingi from nvd api. So the main idea is that this pulls new cve reports, then parses it and makes something like this:
|vendor|plugin|CVSS|cveid|affected versions|
|------|------|----|-----|-----------------|
|WordPress|Core|9.8|CVE-2026-63030|{6.9.0-6.95,7.0.0-7.2.0}|

And then another table with identifying markers for stuff that i want to look out for [wp-admin for wordpress, administrator/manifests/files/joomla.xml for joomla, ...]

The main purpose of this is for [Svetovit](https://github.com/TadeasDitte/Svetovit) to be able to detect vulnerable versions running on shared servers

Unoptimal, definetly

Overkill, probably

Cool as fuck, hell yeah

## NVD

[Get your api key here](https://nvd.nist.gov/developers/request-an-api-key)
This product uses the NVD API but is not endorsed or certified by the NVD.

Limitation: 50 requests/30s

Docs https://nvd.nist.gov/developers/vulnerabilities

## Supported


## Planned support for

- Zenphoto
- Nextcloud
- ProjectSend
- Coppermine
- Gallery
- GateQuest File Manager
- Piwigo
- OpenDocMan
- Pydio
- TinyWebGallery
- ownCloud
- Lychee
- SeedDMS
- Podcast Generator
- iGalerie
- Cheverato
- Atheos

- FluxBB
- SimpleMachinesForum
- Blab
- LibreBooking
- XMB Forum
- DokuWiki
- Elgg
- MyBB
- phpMyChat
- MediaWiki
- phpList
- pmWiki
- jcow
- WebCalendar
- VanillaForums
- phpBB
- Admidio
- mylittleforum
- pH7Builder
- HumHub
- LuxCal

- WebsiteBaker
- Symfony
- Code Igniter
- SPIP
- Textpattern
- Laravel
- b2evolution
- phpwcms
- Omeka
- Grav
- Drupal
- SilverStripe
- ClassicPress
- e107
- Joomla
- GetSimple
- Moodle
- MODx
- Dotclear
- ComposerCMS
- CMSMadeSimple
- phpMyFAQ
- TYPO3
- Mahara
- Zikula
- PHP-Fusion
- TikiWikiCMS
- Wordpress
- Geeklog
- Serendipity
- Chamilo
- Concrete CMS
- Xoops
- CakePHP
- Smarty
- Contao
- Microweber
- SLiMS
- Gibbon
- Backdrop CMS
- Lepton
- WonderDMS
- PluXml
- Nette

- Advanced Poll
- Seo Panel
- LimeSurvey
- Matomo
- Open Web Analytic

- Traq
- GLPI
- Sales Syntax Live Help
- Osclass
- Feng Office
- CubeCart
- Mautic
- SuiteCRM
- Vtiger
- WHMCS
- OpenCart
- Mantis
- Dolibarr
- Revive Adserver
- osTicket
- FreeScpit
- osCommerce
- PrestaShop
- AbanteCart
- Hesk
- FrontAccounting
- OrangeHRM
- Blesta
- ProjecQtOr
- Shopware
- Magneto
- Zen Cart
- Live Helper CHat
- Client exec
- Group office
- InvoiceNinja
- YetiForce
- TastyIgniter
- InvoicePlane
- Anuko Time Tracker
- Mibew Messenger
- LiteCart
- CE Phoenix Cart
- Open Real Estate
- Kanboard
- selfloss
- iTron Clock
- SiteBar
- Form Tools
- Tiny Tiny RSS
- Wallabag
- phpMYAdmin
- YOURLS
- webtrees
- Roundcube
- FreshRSS
- SimplePie
