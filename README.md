# jit-hydrator
An (almost) drop-in replacement for Doctrine ORM's ObjectHydrator that generates custom hydration code depending on the query.

# How it works
After it's registered as a hydrator in the EntityManager, the ORM will call it to hydrate queries at which point it either loads a cached query class, or it generates a new one. The generated class will then hydrate the result set.

# How fast is it ?
In my fairly limited tests it was 50-80% faster than Doctrine ORM's ObjectHydrator.
While for very simple queries (SELECTing <10 columns without JOINs and only 1-2 rows) it might be a bit worse than ObjectHydrator, for bigger queries the performance will be drastically improved.

The table below shows a comparison of the hydrators for a query that returned 1,10,100..1000000 rows. Times are in milliseconds.

| hydrator/rows | 1    | 10   | 100   | 1000   | 10000   | 100000   | 1000000   |
|---------------|------|------|-------|--------|---------|----------|-----------|
| scalar        | 7.77 | 8.68 | 18.83 | 121.61 | 1181.22 | 12616.69 | 195576.84 |
| object        | 6.22 | 7.31 | 21.13 | 137.16 | 1281.48 | 12430.54 | 134498.12 |
| array         | 7.77 | 8.66 | 18.73 | 119.54 | 1137.93 | 11265.48 | 118089.68 |
| **jit**       | 3.07 | 3.42 | 6.12  | 33.05  | 287.47  | 2686.87  | 29322.57  |

# Installation

### 1. Install package via composer
```bash
composer require ovrflo/jit-hydrator
```

### 2. a. if using Symfony >=4.0
```yaml
# config/packages/doctrine.yaml, under doctrine.orm key, add
#doctrine:
#    orm:
        hydrators:
            jit: Ovrflo\JitHydrator\JitObjectHydrator
```

### 2. b. if using Doctrine ORM without a framework:
```php
$entityManager->getConfiguration()->addCustomHydrationMode('jit', JitObjectHydrator::class);
```

### 3. Use it for a specific query
```php
        $query = $queryBuilder
            ->getQuery()
            ->setHint(Query::HINT_INCLUDE_META_COLUMNS, true)
            ->getResult('jit')
        ;
```

### Step 3 explained
After you registered it, in order to use it you need 2 things. The most important one is `getResult('jit')` which tells Doctrine to use That hydrator.
The second thing is `->setHint(Query::HINT_INCLUDE_META_COLUMNS, true)`. That's needed because otherwise Doctrine doesn't pass relation metadata to the Hydrator and it won't be able to work without it. Currently, only Doctrine's own ObjectHydrator receives this info without that Query Hint.

# Status
While this is currently running in production on a relatively small app, I wouldn't dare calling it production-ready. I'm sure there are a few bugs to squash in there. In my limited testing it worked, significantly lowering response times and CPU usage.
Less CPU time means happier users and also lower power bills. Sure, we don't tend to think about power bills, but if you're running a huge infrastructure that heavily uses Doctrine ORM, it might actually make a difference. If power usage isn't a concern, than at least consider having more CPU headroom for your codebase.
I personally encourage any one that needs a faster hydrator to test it and maybe even send some issues and/or PRs 😊

# Credits
I would like to thank @ocramius for his insightful blog post on [Doctrine ORM Hydration](https://ocramius.github.io/blog/doctrine-orm-optimization-hydration/), which gave me the idea to implement this. At the end of his blog post he suggested that performance may be improved by generating hydrator code.
