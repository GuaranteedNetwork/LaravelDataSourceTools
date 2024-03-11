<?php

namespace GuaranteedSoftware\Laravel\DatasourceTools\Providers;

use Carbon\Carbon;
use GuaranteedSoftware\Laravel\DatasourceTools\Console\Commands\PartitionTableByDateRange;
use GuaranteedSoftware\Laravel\DatasourceTools\Console\Commands\UpdateTablePartitions;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Bootstrap class auto-discovered by Laravel.  It registers the datasource commands.
 */
class DatasourceToolsServiceProvider extends ServiceProvider
{

    /**
     * Bootstrap any application services.  This differs from the register
     * method by executing after all other service provider register methods
     * have executed, including the complete Laravel Framework.
     *
     * Any universal bootstrapping needed that require the entire system to be
     * available should be accomplished here
     *
     * Here, we use this to register our custom commands.
     *
     * @return void
     */
    public function boot()
    {
        ///////////////////////////////////////////////
        // Bootstrap Artisan Commands
        ///////////////////////////////////////////////
        if ($this->app->runningInConsole()) {
            $this->commands(
                [
                    PartitionTableByDateRange::class,
                    UpdateTablePartitions::class
                ]
            );
        }

        ///////////////////////////////////////////////
        // Bootstrap DB migration extensions
        ///////////////////////////////////////////////
        DB::macro(
            'partitionByDateRange',
            /**
             * Splits a table into partitions by dates across a range of dates
             *
             * @param string $tableName The table to be partitioned
             * @param Carbon $startDate The starting partition date in the format of {@see Constants::MYSQL_DATE_FORMAT}, typically, in the past.
             * @param Carbon $endDate The concluding partition date in the format of {@see Constants::MYSQL_DATE_FORMAT}, typically, in the future.
             * @param string $partitionColumnName The name of the indexed column to partition against
             */
            function (string $tableName, Carbon $startDate, Carbon $endDate, string $partitionColumnName = 'date_partition_column') {
                /**
                 * @var DatabaseManager $this is the DatabaseManager rebound by \Illuminate\Support\Traits\Macroable::__call
                 */

                $dbManagerStatements = [];

                Schema::table(
                    $tableName,
                    function (Blueprint $table) use (&$dbManagerStatements, $startDate, $endDate, $partitionColumnName) {
                        Artisan::call(
                            PartitionTableByDateRange::class,
                            [
                                'tableName' => $table->getTable(),
                                'startDate' => $startDate,
                                'endDate' => $endDate,
                                '--partitionColumnName' => $partitionColumnName,
                                '--blueprint' => $table,
                                '--databaseManagerStatements' => $dbManagerStatements,
                            ]
                        );
                    }
                );

                foreach ($dbManagerStatements as $statement) {
                    DB::statement($statement);
                }
            }
        );

        DB::macro(
            'removeDateRangePartition',
            /**
             * Removes partitions on a table partitioned by "DB::partitionByDateRange"
             *
             * @param string $tableName The table where the partition will be removed
             * @param string $partitionColumnName The name of the indexed column that is partition against
             */
            function (string $tableName, string $partitionColumnName = 'date_partition_column') {
                /**
                 * @var DatabaseManager $this is the DatabaseManager rebound by \Illuminate\Support\Traits\Macroable::__call
                 */

                DB::statement("ALTER TABLE $tableName REMOVE PARTITIONING;");

                Schema::table(
                    $tableName,
                    function (Blueprint $table) use ($partitionColumnName) {
                        $table->dropPrimary(['id', $partitionColumnName]);
                        $table->primary(['id']);
                        $table->dropUnique(['id']);

                        $table->dropIndex([$partitionColumnName]);
                        $table->dropColumn([$partitionColumnName]);
                    }
                );
            }
        );
    }
}
