# mysqli

[![PHPUnit Testing](https://github.com/mondrake/mysqli/actions/workflows/test.yml/badge.svg)](https://github.com/mondrake/mysqli/actions/workflows/test.yml)

Database driver for MySQLi.

For Drupal 10, with patches for the following issues applied:

Issue              | Description
-------------------|----------------------------------------------------------------------------------------------|
#3110546           | Allow contributed modules (mostly database drivers) to override tests in core |
#3256642           | Autoload classes of database drivers modules' dependencies |

Known issues
------------
- `mysqli::rollback()` returns TRUE if there's no active transaction. This is diverging from PDO MySql. `TransactionTest::testTransactionWithDdlStatement()`
  fails for this reason. A PHP bug report exists, https://bugs.php.net/bug.php?id=81533.
- Apparently, `mysqli::rollback(0, 'savepoint-1')` does not respect the savepoint passed in, and rolls back the entire
  transaction. I had to revert to using `mysqli::query('ROLLBACK TO SAVEPOINT savepoint-1')` to get transaction tests
  pass. Could not find reported bugs for this.

