<?php

namespace Illuminate\Tests\Integration\Database\EloquentHasManyThroughTest;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Tests\Integration\Database\DatabaseTestCase;
use Spatie\LaravelRay\RayServiceProvider;

class EloquentHasManyThroughTest extends DatabaseTestCase
{
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug')->nullable();
            $table->integer('team_id')->nullable();
            $table->string('name');
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('owner_id')->nullable();
            $table->string('owner_slug')->nullable();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('parent_id')->nullable();
            $table->softDeletes();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('category_id');
        });
    }

    public function testBasicCreateAndRetrieve()
    {
        $user = User::create(['name' => Str::random()]);

        $team1 = Team::create(['owner_id' => $user->id]);
        $team2 = Team::create(['owner_id' => $user->id]);

        $mate1 = User::create(['name' => 'John', 'team_id' => $team1->id]);
        $mate2 = User::create(['name' => 'Jack', 'team_id' => $team2->id, 'slug' => null]);

        User::create(['name' => Str::random()]);

        $this->assertEquals([$mate1->id, $mate2->id], $user->teamMates->pluck('id')->toArray());
        $this->assertEquals([$mate1->id, $mate2->id], $user->teamMatesWithPendingRelation->pluck('id')->toArray());
        $this->assertEquals([$user->id], User::has('teamMates')->pluck('id')->toArray());
        $this->assertEquals([$user->id], User::has('teamMatesWithPendingRelation')->pluck('id')->toArray());

        $result = $user->teamMates()->first();
        $this->assertEquals(
            $mate1->refresh()->getAttributes() + ['laravel_through_key' => '1'],
            $result->getAttributes()
        );

        $result = $user->teamMatesWithPendingRelation()->first();
        $this->assertEquals(
            $mate1->refresh()->getAttributes() + ['laravel_through_key' => '1'],
            $result->getAttributes()
        );

        $result = $user->teamMates()->firstWhere('name', 'Jack');
        $this->assertEquals(
            $mate2->refresh()->getAttributes() + ['laravel_through_key' => '1'],
            $result->getAttributes()
        );

        $result = $user->teamMatesWithPendingRelation()->firstWhere('name', 'Jack');
        $this->assertEquals(
            $mate2->refresh()->getAttributes() + ['laravel_through_key' => '1'],
            $result->getAttributes()
        );
    }

    public function testGlobalScopeColumns()
    {
        $user = User::create(['name' => Str::random()]);

        $team1 = Team::create(['owner_id' => $user->id]);

        User::create(['name' => Str::random(), 'team_id' => $team1->id]);

        $teamMates = $user->teamMatesWithGlobalScope;
        $this->assertEquals(['id' => 2, 'laravel_through_key' => 1], $teamMates[0]->getAttributes());

        $teamMates = $user->teamMatesWithGlobalScopeWithPendingRelation;
        $this->assertEquals(['id' => 2, 'laravel_through_key' => 1], $teamMates[0]->getAttributes());
    }

    public function testHasSelf()
    {
        $user = User::create(['name' => Str::random()]);

        $team = Team::create(['owner_id' => $user->id]);

        User::create(['name' => Str::random(), 'team_id' => $team->id]);

        $users = User::has('teamMates')->get();
        $this->assertCount(1, $users);

        $users = User::has('teamMatesWithPendingRelation')->get();
        $this->assertCount(1, $users);
    }

    public function testHasSelfCustomOwnerKey()
    {
        $user = User::create(['slug' => Str::random(), 'name' => Str::random()]);

        $team = Team::create(['owner_slug' => $user->slug]);

        User::create(['name' => Str::random(), 'team_id' => $team->id]);

        $users = User::has('teamMatesBySlug')->get();
        $this->assertCount(1, $users);

        $users = User::has('teamMatesBySlugWithPendingRelationship')->get();
        $this->assertCount(1, $users);
    }

    public function testHasSameParentAndThroughParentTable()
    {
        Category::create();
        Category::create();
        Category::create(['parent_id' => 1]);
        Category::create(['parent_id' => 2])->delete();

        Product::create(['category_id' => 3]);
        Product::create(['category_id' => 4]);

        $categories = Category::has('subProducts')->get();

        $this->assertEquals([1], $categories->pluck('id')->all());
    }
	
	public function testUpdateOrCreateWillNotUseIdFromParentModel()
	{
		// On Laravel 10.21.0, a bug was introduced that would update the wrong model when using `updateOrCreate()`,
		// because the UPDATE statement would target a model based on the ID from the parent instead of the actual
		// conditions that the `updateOrCreate()` targeted. This test replicates the case that causes this bug.
		
		// Manually provide IDs, keep ID 1 and 2 free for the team-mates.
		$user1 = User::create(['name' => Str::random(), 'id' => 3]);
		$user2 = User::create(['name' => Str::random(), 'id' => 4]);
		
		$team1 = Team::create(['owner_id' => $user1->id]);
		$team2 = Team::create(['owner_id' => $user2->id]);
		
		$teamMate1 = User::create(['name' => 'John', 'slug' => 'john-slug', 'team_id' => $team1->id, 'id' => 2]);
		// $teamMate2->id should be the same as the $team1->id for the bug to occur.
		$teamMate2 = User::create(['name' => 'Jane', 'slug' => 'jane-slug', 'team_id' => $team2->id, 'id' => $team1->id]);
		
		$this->assertSame(2, $teamMate1->id);
		$this->assertSame(1, $teamMate2->id);
		
		$this->assertSame(2, $teamMate1->refresh()->id);
		$this->assertSame(1, $teamMate2->refresh()->id);
		
		$this->assertSame('john-slug', $teamMate1->slug);
		$this->assertSame('jane-slug', $teamMate2->slug);
		
		$this->assertSame('john-slug', $teamMate1->refresh()->slug);
		$this->assertSame('jane-slug', $teamMate2->refresh()->slug);
		
		$user1->teamMates()->updateOrCreate([
			'name' => 'John',
			// The `updateOrCreate()` method tries to retrieve an existing model first like `->where($conditions)->first()`.
			// In our case, that will return the model with the name `John`. However, the ID of the model with the name
			// `John` is hydrated to `1` – where the actual ID should be `2` for the model with the name `John` (see
			// the assertions above). If the `->where($conditions)->first()` return a model, a `->fill()->save()`
			// action is executed. Because the ID is incorrectly hydrated to `1`, it will now update the Jane
			// model with *all* the attributes of the `John` model, instead of updating the `John` model.
		], [
			'slug' => 'john-doe',
		]);
		
		// Expect $teamMate1's slug to be updated to john-doe instead of john-old.
		$this->assertSame('john-doe', $teamMate1->fresh()->slug);
		// $teamMate2 should not be updated, because it belongs to a different user altogether.
		$this->assertSame('jane-slug', $teamMate2->fresh()->slug);
	}
}

class User extends Model
{
    public $table = 'users';
    public $timestamps = false;
    protected $guarded = [];

    public function teamMates()
    {
        return $this->hasManyThrough(self::class, Team::class, 'owner_id', 'team_id');
    }

    public function teamMatesWithPendingRelation()
    {
        return $this->through($this->ownedTeams())
            ->has(fn (Team $team) => $team->members());
    }

    public function teamMatesBySlug()
    {
        return $this->hasManyThrough(self::class, Team::class, 'owner_slug', 'team_id', 'slug');
    }

    public function teamMatesBySlugWithPendingRelationship()
    {
        return $this->through($this->hasMany(Team::class, 'owner_slug', 'slug'))
            ->has(fn ($team) => $team->hasMany(User::class, 'team_id'));
    }

    public function teamMatesWithGlobalScope()
    {
        return $this->hasManyThrough(UserWithGlobalScope::class, Team::class, 'owner_id', 'team_id');
    }

    public function teamMatesWithGlobalScopeWithPendingRelation()
    {
        return $this->through($this->ownedTeams())
            ->has(fn (Team $team) => $team->membersWithGlobalScope());
    }

    public function ownedTeams()
    {
        return $this->hasMany(Team::class, 'owner_id');
    }
}

class UserWithGlobalScope extends Model
{
    public $table = 'users';
    public $timestamps = false;
    protected $guarded = [];

    public static function boot()
    {
        parent::boot();

        static::addGlobalScope(function ($query) {
            $query->select('users.id');
        });
    }
}

class Team extends Model
{
    public $table = 'teams';
    public $timestamps = false;
    protected $guarded = [];

    public function members()
    {
        return $this->hasMany(User::class, 'team_id');
    }

    public function membersWithGlobalScope()
    {
        return $this->hasMany(UserWithGlobalScope::class, 'team_id');
    }
}

class Category extends Model
{
    use SoftDeletes;

    public $timestamps = false;
    protected $guarded = [];

    public function subProducts()
    {
        return $this->hasManyThrough(Product::class, self::class, 'parent_id');
    }
}

class Product extends Model
{
    public $timestamps = false;
    protected $guarded = [];
}
