# Database Layer (db)
Module db provides a minimal database access layer with Active Record style operations. 
It relies on implicit table resolution and provides basic query construction.

# Active Record
Tables are declared by extending \db\ar. Magic properties track row changes to dispatch minimal SQL updates.
``` 
lass User extends \db\ar {
    const TBL = 'users'; // PK defaults to 'id'
}

$u = new User();
$u->name = "Marc"; 
$u->save(); // obtains auto-ID

$u->name = "AZ";
$u->save(); // updates only 'name'

$u->reload();
$u->del();
``` 

Foreign keys can be assigned using the ref() method, which infers the column name as fk_<table_name>.
```
$company = Company::find(1);

$user = clone clone $u; // (example)
$user->ref($company);   // resolves to: fk_company = $company->id()

// Reverse lookup
$users = $company->rels(User::class);

```

# Query Builder
The where method initiates a select statement. 
Query objects, Active Record instances, or rows collections can be passed directly to compose subqueries or IN clauses. 
If an empty object is injected, it resolves to a selempty object to bypass the database execution entirely.
```
$sel = User::where("status = ?", 'active');
$rows = $sel->fetch();
$first = $sel->first();
$count = $sel->count();

// Object injection
$company = Company::find(5);
User::where($company); // WHERE fk_company = 5

// Subquery injection
$active_companies = Company::where("status = ?", 'active');
User::where($active_companies); // WHERE fk_company IN (SELECT id FROM ...)

```


# Data Shaping
The fetch() method returns a \db\rows instance, implementing Iterator, Countable, and ArrayAccess. It includes utilities to format the result set.
```
$rows = User::all();

foreach ($rows as $user) {
    // Memory-safe iteration using WeakReferences internally
}

$rows->arr();           // Array of associative arrays
$rows->map("id");       // Map of AR objects keyed by 'id'
$rows->col("email");    // Flat array of column values
$rows->arrmap("email"); // Map of associative arrays keyed by 'email'
$rows->as_items();      // Normalized UI-ready items array [id, title, detail, icon]

```

# Bulk Operations
Use ar_buffer to batch saves/updates into a single SQL query.
```
$buffer = new \db\ar_buffer();

foreach($data_array as $data) {
    $u = new User($data);
    $u->status = 'processed';
    $buffer->add($u);
}

$buffer->flush(); // Executes INSERT ... ON DUPLICATE KEY UPDATE

```