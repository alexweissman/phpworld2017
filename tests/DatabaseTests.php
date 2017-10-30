<?php
/**
 * Hacking Laravel: Custom Relationships in Eloquent
 *
 * @link      https://github.com/alexweissman/phpworld2017
 * @see       https://world.phparch.com/sessions/hacking-laravel-custom-relationships-in-eloquent/
 * @license   MIT
 */
namespace App\Tests;

use Exception;

use App\Database\Models\Model;
use App\Log\ArrayHandler;
use App\Log\MixedFormatter;

use Illuminate\Container\Container;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Events\Dispatcher;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

use PHPUnit\Framework\TestCase;

/**
 * Class DatabaseTests.
 *
 * @author Alex Weissman (https://alexanderweissman.com)
 */
class DatabaseTests extends TestCase
{
    protected $schemaName = 'default';

    public static $arrayHandler = null;

    public static function setUpBeforeClass()
    {
        $capsule = new DB;

        $capsule->addConnection([
            'driver'    => 'sqlite',
            'database'  => ':memory:'
        ]);

        $queryEventDispatcher = new Dispatcher(new Container);

        $capsule->setEventDispatcher($queryEventDispatcher);

        // Make this Capsule instance available globally via static methods
        $capsule->setAsGlobal();

        // Setup the Eloquent ORM
        $capsule->bootEloquent();

        // Set up a query logger
        $logger = new Logger('query');

        $formatter = new MixedFormatter(null, null, true);

        static::$arrayHandler = new ArrayHandler();
        static::$arrayHandler->setFormatter($formatter);

        $logger->pushHandler(static::$arrayHandler);

        if (PHP_SAPI == 'cli') {
            $logFile = __DIR__ . '/../log/queries.log';
            $handler = new StreamHandler($logFile);
            $handler->setFormatter($formatter);

            $logger->pushHandler($handler);
        }

        $capsule->connection()->enableQueryLog();

        // Register listener to log performed queries
        $queryEventDispatcher->listen(QueryExecuted::class, function ($query) use ($logger) {
            $logger->debug("Query executed on database [{$query->connectionName}]:", [
                'query'    => $query->sql,
                'bindings' => $query->bindings,
                'time'     => $query->time . ' ms'
            ]);
        });
    }

    /**
     * Setup the database schema.
     *
     * @return void
     */
    public function setUp()
    {
        $this->createSchema();
    }

    protected function createSchema()
    {
        $this->schema($this->schemaName)->create('users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        // Users have multiple roles... (m:m)
        $this->schema($this->schemaName)->create('role_users', function ($table) {
            $table->integer('user_id')->unsigned();
            $table->integer('role_id')->unsigned();
        });

        $this->schema($this->schemaName)->create('jobs', function($table) {
            $table->integer('user_id')->unsigned();
            $table->integer('location_id')->unsigned();
            $table->integer('role_id')->unsigned();
            $table->string('title');
        });

        $this->schema($this->schemaName)->create('roles', function ($table) {
            $table->increments('id');
            $table->string('slug');
        });

        $this->schema($this->schemaName)->create('locations', function($table) {
            $table->increments('id');
            $table->string('name');
        });
    }

    /**
     * Tear down the database schema.
     *
     * @return void
     */
    public function tearDown()
    {
        $this->schema($this->schemaName)->drop('users');
        $this->schema($this->schemaName)->drop('role_users');
        $this->schema($this->schemaName)->drop('roles');
        $this->schema($this->schemaName)->drop('jobs');
        $this->schema($this->schemaName)->drop('locations');

        Relation::morphMap([], false);
    }

    /**
     * Tests...
     */
    public function testBelongsToManyRelationship()
    {
        $this->generateRoles();

        $user = EloquentTestUser::create(['name' => 'David']);

        $user->roles()->attach([1,2]);

        // Test retrieval of pivots as well
        $this->assertEquals([
            [
                'id' => 1,
                'slug' => 'forager',
                'pivot' => [
                    'user_id' => 1,
                    'role_id' => 1
                ]
            ],
            [
                'id' => 2,
                'slug' => 'soldier',
                'pivot' => [
                    'user_id' => 1,
                    'role_id' => 2
                ]
            ]
        ], $user->roles->toArray());
    }

    public function testBelongsToTernary()
    {
        $user = EloquentTestUser::create(['name' => 'David']);

        $this->generateLocations();
        $this->generateRoles();
        $this->generateJobs();

        $expectedRoles = [
            [
                'id' => 2,
                'slug' => 'soldier',
                'pivot' => [
                    'user_id' => 1,
                    'role_id' => 2
                ]
            ],
            [
                'id' => 3,
                'slug' => 'egg-layer',
                'pivot' => [
                    'user_id' => 1,
                    'role_id' => 3
                ]
            ]
        ];

        $roles = $user->jobRoles;
        $this->assertEquals($expectedRoles, $roles->toArray());
    }

    public function testBelongsToTernaryEagerLoad()
    {
        $user = EloquentTestUser::create(['name' => 'David']);

        $this->generateLocations();
        $this->generateRoles();
        $this->generateJobs();

        $expectedRoles = [
            [
                'id' => 2,
                'slug' => 'soldier',
                'pivot' => [
                    'user_id' => 1,
                    'role_id' => 2
                ]
            ],
            [
                'id' => 3,
                'slug' => 'egg-layer',
                'pivot' => [
                    'user_id' => 1,
                    'role_id' => 3
                ]
            ]
        ];

        $users = EloquentTestUser::with('jobRoles')->get();
        $this->assertEquals($expectedRoles, $users->toArray()[0]['job_roles']);
    }

    public function testBelongsToTernaryWithTertiary()
    {
        $user = EloquentTestUser::create(['name' => 'David']);

        $this->generateLocations();
        $this->generateRoles();
        $this->generateJobs();

        $expectedJobs = [
            [
                'id' => 2,
                'slug' => 'soldier',
                'pivot' => [
                    'user_id' => 1,
                    'role_id' => 2
                ],
                'locations' => [
                    [
                        'id' => 1,
                        'name' => 'Hatchery',
                        'pivot' => [
                            'title' => 'Grunt',
                            'location_id' => 1,
                            'role_id' => 2
                        ]
                    ],
                    [
                        'id' => 2,
                        'name' => 'Nexus',
                        'pivot' => [
                            'title' => 'Sergeant',
                            'location_id' => 2,
                            'role_id' => 2
                        ]
                    ]
                ]
            ],
            [
                'id' => 3,
                'slug' => 'egg-layer',
                'pivot' => [
                    'user_id' => 1,
                    'role_id' => 3
                ],
                'locations' => [
                    [
                        'id' => 2,
                        'name' => 'Nexus',
                        'pivot' => [
                            'title' => 'Queen',
                            'location_id' => 2,
                            'role_id' => 3
                        ]
                    ]
                ]
            ]
        ];

        $jobs = $user->jobs()->withPivot('title')->get();
        $this->assertEquals($expectedJobs, $jobs->toArray());

        // Test eager loading
        $users = EloquentTestUser::with(['jobs' => function ($relation) {
            return $relation->withPivot('title');
        }])->get();

        $this->assertEquals($expectedJobs, $users->toArray()[0]['jobs']);
    }

    public function testBelongsToTernaryWithTertiaryEagerLoad()
    {
        $user1 = EloquentTestUser::create(['name' => 'David']);
        $user2 = EloquentTestUser::create(['name' => 'Alex']);

        $this->generateLocations();
        $this->generateRoles();
        $this->generateJobs();

        $users = EloquentTestUser::with('jobs')->get();

        $this->assertEquals([
            [
                'id' => 1,
                'name' => 'David',
                'jobs' => [
                    [
                        'id' => 2,
                        'slug' => 'soldier',
                        'pivot' => [
                            'user_id' => 1,
                            'role_id' => 2
                        ],
                        'locations' => [
                            [
                                'id' => 1,
                                'name' => 'Hatchery',
                                'pivot' => [
                                    'location_id' => 1,
                                    'role_id' => 2
                                ]
                            ],
                            [
                                'id' => 2,
                                'name' => 'Nexus',
                                'pivot' => [
                                    'location_id' => 2,
                                    'role_id' => 2
                                ]
                            ]
                        ]
                    ],
                    [
                        'id' => 3,
                        'slug' => 'egg-layer',
                        'pivot' => [
                            'user_id' => 1,
                            'role_id' => 3
                        ],
                        'locations' => [
                            [
                                'id' => 2,
                                'name' => 'Nexus',
                                'pivot' => [
                                    'location_id' => 2,
                                    'role_id' => 3
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            [
                'id' => 2,
                'name' => 'Alex',
                'jobs' => [
                    [
                        'id' => 3,
                        'slug' => 'egg-layer',
                        'pivot' => [
                            'user_id' => 2,
                            'role_id' => 3
                        ],
                        'locations' => [
                            [
                                'id' => 1,
                                'name' => 'Hatchery',
                                'pivot' => [
                                    'location_id' => 1,
                                    'role_id' => 3
                                ]
                            ],
                        ]
                    ]
                ]
            ]
        ], $users->toArray());
    }

    /**
     * Helpers...
     */

    /**
     * Get a database connection instance.
     *
     * @return \Illuminate\Database\Connection
     */
    protected function connection($connection = 'default')
    {
        return Model::getConnectionResolver()->connection($connection);
    }

    /**
     * Get a schema builder instance.
     *
     * @return \Illuminate\Database\Schema\Builder
     */
    protected function schema($connection = 'default')
    {
        return $this->connection($connection)->getSchemaBuilder();
    }

    /**
     * Generate some sample jobs.  A job is a unique triplet of role, location, and user.
     */
    protected function generateJobs()
    {
        /**
         * Sample data

        | user_id | role_id | location_id |
        |---------|---------|-------------|
        | 1       | 2       | 1           |
        | 1       | 2       | 2           |
        | 1       | 3       | 2           |
        | 2       | 3       | 1           |
        */

        return [
            EloquentTestJob::create([
                'role_id' => 2,
                'location_id' => 1,
                'user_id' => 1,
                'title' => 'Grunt'
            ]),
            EloquentTestJob::create([
                'role_id' => 2,
                'location_id' => 2,
                'user_id' => 1,
                'title' => 'Sergeant'
            ]),
            EloquentTestJob::create([
                'role_id' => 3,
                'location_id' => 2,
                'user_id' => 1,
                'title' => 'Queen'
            ]),
            EloquentTestJob::create([
                'role_id' => 3,
                'location_id' => 1,
                'user_id' => 2,
                'title' => 'Demi-queen'
            ])
        ];
    }

    protected function generateRoles()
    {
        return [
            EloquentTestRole::create([
                'id' => 1,
                'slug' => 'forager'
            ]),

            EloquentTestRole::create([
                'id' => 2,
                'slug' => 'soldier'
            ]),

            EloquentTestRole::create([
                'id' => 3,
                'slug' => 'egg-layer'
            ])
        ];
    }

    protected function generateLocations()
    {
        return [
            EloquentTestLocation::create([
                'id' => 1,
                'name' => 'Hatchery'
            ]),

            EloquentTestLocation::create([
                'id' => 2,
                'name' => 'Nexus'
            ])
        ];
    }
}

/**
 * Eloquent Models...
 */
class EloquentTestModel extends Model
{
    protected $connection = 'default';

    public $timestamps = false;
}

class EloquentTestUser extends EloquentTestModel
{
    protected $table = 'users';
    protected $guarded = [];

    /**
     * Get all of the user's unique roles based on their jobs.
     */
    public function jobRoles()
    {
        $relation = $this->belongsToTernary(
            EloquentTestRole::class,
            'jobs',
            'user_id',
            'role_id'
        );

        return $relation;
    }

    /**
     * Get all of the user's unique roles based on their jobs as a tertiary relationship.
     */
    public function jobs()
    {
        $relation = $this->belongsToTernary(
            EloquentTestRole::class,
            'jobs',
            'user_id',
            'role_id'
        )->withTertiary(EloquentTestLocation::class, null, 'location_id');

        return $relation;
    }

    /**
     * Get all roles to which this user belongs.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function roles()
    {
        return $this->belongsToMany(EloquentTestRole::class, 'role_users', 'user_id', 'role_id');
    }
}

class EloquentTestJob extends EloquentTestModel
{
    protected $table = 'jobs';
    protected $guarded = [];

    /**
     * Get the role for this job.
     */
    public function role()
    {
        return $this->belongsTo(EloquentTestRole::class, 'role_id');
    }
}

class EloquentTestRole extends EloquentTestModel
{
    protected $table = 'roles';
    protected $guarded = [];
}

class EloquentTestLocation extends EloquentTestModel
{
    protected $table = 'locations';
    protected $guarded = [];
}
