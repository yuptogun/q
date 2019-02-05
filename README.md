# Q: the simple PDO query builder

Inspired by Codeigniter Query Builder class.

## Quick Start

### Installation

```cmd
composer require yuptogun/q
```

### Basic Usage

```php
$query = new Yuptogun\Q($connectionArray);

// You name it and it (is going to) has the right method with right pattern for that.
$toBeSuspended = $query->select('users')
                       ->where([
                            'suspended' => null,
                            'suspension_notified' => null
                        ])
                       ->where('last_login', '<', $elevenMonthsAgo)
                       ->get();

// it always returns an array of objects.
foreach ($toBeSuspended as $s) {
    Mail::send($s->email,
               'Please visit again in next 30 days!',
               'Or your account will be suspended, that\'s the privacy law...');

    // you just call get() or run() to your Q and it will be fresh ready for the next query building.
    $notiQuery = $query->update('users',
                                ['id' => $s->id],
                                ['suspension_notified' => true]);

    // You can access to the latest raw SQL statement always executable, you're welcome.
    if (!$notiQuery->run()) {
        Logger::error('User '.$s->id.' failed to get notified of future suspension. The raw SQL: '.$notiQuery->Q);
    }
}
```

## TO DO

- [x] `get()`, `run()`
- [x] SELECT `select()`
- [x] INSERT `insert()`
- [x] UPDATE `update()`
- [ ] DELETE `delete()`
- [x] WHERE `where()`
- [x] ORDER BY `orderBy()`
- [x] LIMIT OFFSET `page()`
- [ ] LEFT JOIN
- [ ] Support for any other kind of PDO adapters. (Postgre, NoSQL...)