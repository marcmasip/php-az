# About php-az
It's an opinionated PHP solution exploring pragmatic minimalism. 
Ultimately, it provides a set of functions and structures to deal with common operations in a rapid and convenient way, surfacing the existing infrastructure.
Note: Because it's an experiment in progress do not use in production.
I'll try to collect successful utilities and examples in this repo.

## 🧩 Modules
A modular approach for resource, component, and dependency containers.

## 🚚 Deploy
Provide folders with modules via *$MOD_MAP* ( local_path => url_path).
Namespace and file resolution as: mod/group/name.php, mod/group.php or mod/mod.php.
Your *index.php* loads *init.php*.

## ⚡️ Actions
Named entry points located at each *module/action/name.php*.
And if you don't have a better routing idea, *mod_action()* parses *?mod=module:action* for you, detects php-cli to use *module/cli/name.php* instead.

# Basic modules php-az-*
A set of small common solutions

## 📟 db
Database Access Layer (DAL) / Active Record, and Query composition.
(See more on module/db/README.md)

## 🏛 form 
Only Forms manages Fields and Processes. Centralizes app API definition, ACL, and CSRF operations.
(See more on module/form/README.md)

## 📽 media
(Pending description...)

Other modules and utilities are available in the repo.