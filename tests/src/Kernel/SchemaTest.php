<?php

namespace Drupal\Tests\drudbal\Kernel;

use Drupal\Core\Database\Database;
use Drupal\KernelTests\Core\Database\SchemaTest as SchemaTestBase;

/**
 * Tests table creation and modification via the schema API.
 *
 * @group Database
 */
class SchemaTest extends SchemaTestBase {

  /**
   * @covers \Drupal\Core\Database\Driver\mysql\Schema::introspectIndexSchema
   * @covers \Drupal\Core\Database\Driver\pgsql\Schema::introspectIndexSchema
   * @covers \Drupal\Core\Database\Driver\sqlite\Schema::introspectIndexSchema
   */
  public function testIntrospectIndexSchema() {
    $table_specification = [
      'fields' => [
        'id'  => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
        'test_field_1'  => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
        'test_field_2'  => [
          'type' => 'int',
          'default' => 0,
        ],
        'test_field_3'  => [
          'type' => 'int',
          'default' => 0,
        ],
        'test_field_4'  => [
          'type' => 'int',
          'default' => 0,
        ],
        'test_field_5'  => [
          'type' => 'int',
          'default' => 0,
        ],
      ],
      'primary key' => ['id', 'test_field_1'],
      'unique keys' => [
        'test_field_2' => ['test_field_2'],
        'test_field_3_test_field_4' => ['test_field_3', 'test_field_4'],
      ],
      'indexes' => [
        'test_field_4' => ['test_field_4'],
        'test_field_4_test_field_5' => ['test_field_4', 'test_field_5'],
      ],
    ];

    $table_name = strtolower($this->getRandomGenerator()->name());
    $this->schema->createTable($table_name, $table_specification);

    unset($table_specification['fields']);

    $introspect_index_schema = new \ReflectionMethod(get_class($this->schema), 'introspectIndexSchema');
    $introspect_index_schema->setAccessible(TRUE);
    $index_schema = $introspect_index_schema->invoke($this->schema, $table_name);

    // Oracle is using a custom naming scheme for its indexes, so
    // we need to adjust the initial table specification.
    if ($this->connection->databaseType() === 'oracle') {
      foreach ($table_specification['unique keys'] as $original_index_name => $columns) {
        unset($table_specification['unique keys'][$original_index_name]);
        $new_index_name = $this->connection->getDbalExtension()->getDbIndexName('indexExists', $this->connection->getDbalConnection()->getSchemaManager()->createSchema(), $table_name, $original_index_name);
        $table_specification['unique keys'][$new_index_name] = $columns;
      }

      foreach ($table_specification['indexes'] as $original_index_name => $columns) {
        unset($table_specification['indexes'][$original_index_name]);
        $new_index_name = $this->connection->getDbalExtension()->getDbIndexName('indexExists', $this->connection->getDbalConnection()->getSchemaManager()->createSchema(), $table_name, $original_index_name);
        $table_specification['indexes'][$new_index_name] = $columns;
      }
    }

    $this->assertEquals($table_specification, $index_schema);
  }

  /**
   * Tests the findTables() method.
   */
  public function testFindTables() {
    // We will be testing with three tables, two of them using the default
    // prefix and the third one with an individually specified prefix.
    // Set up a new connection with different connection info.
    $connection_info = Database::getConnectionInfo();

    // Add per-table prefix to the second table.
    $new_connection_info = $connection_info['default'];
    $new_connection_info['prefix']['test2'] = $new_connection_info['prefix']['default'] . 's_';
    Database::addConnectionInfo('test', 'default', $new_connection_info);
    Database::setActiveConnection('test');
    $test_schema = Database::getConnection()->schema();

    // Create the tables.
    $table_specification = [
      'description' => 'Test table.',
      'fields' => [
        'id'  => [
          'type' => 'int',
          'default' => NULL,
        ],
      ],
    ];
    $test_schema->createTable('test_1_table', $table_specification);
    $test_schema->createTable('test2', $table_specification);
    $test_schema->createTable('table_3_test', $table_specification);

    // Check the "all tables" syntax.
    $tables = $test_schema->findTables('%');
    sort($tables);
    $expected = [
      // The 'config' table is added by
      // \Drupal\KernelTests\KernelTestBase::containerBuild().
      'config',
      'table_3_test',
      // This table uses a per-table prefix, yet it is returned as un-prefixed.
      'test2',
      'test_1_table',
    ];
    $this->assertEquals($expected, $tables);

    // Check the restrictive syntax.
    $tables = $test_schema->findTables('test%');
    sort($tables);
    $expected = [
      'test2',
      'test_1_table',
    ];
    $this->assertEquals($expected, $tables);

    // Go back to the initial connection.
    Database::setActiveConnection('default');
  }

  /**
   * Tests handling of uppercase table names.
   */
  public function testUpperCaseTableName() {
    $table_name = 'UPPER_CASE';

    // Create the tables.
    $table_specification = [
      'description' => 'Test table.',
      'fields' => [
        'id'  => [
          'type' => 'int',
          'default' => NULL,
        ],
      ],
    ];
    $this->schema->createTable($table_name, $table_specification);

    $this->assertTrue($this->schema->tableExists($table_name), 'Table with uppercase table name exists');
    $this->assertContains($table_name, $this->schema->findTables('%'));
    $this->assertTrue($this->schema->dropTable($table_name), 'Table with uppercase table name dropped');
  }

}
