<?php

declare(strict_types=1);

test('strings: capitalising, snake_case, escaping', function () {
    eq('First Name', __ucwords('first_name'));
    eq('First Name X', __ucwords('first_name-x'));
    eq('First_name', __ucwords('first_name', false));
    eq('', __ucwords(null));
    eq('Hello', __ucfirst('hello'));
    eq('', __ucfirst(null));
    eq('Hello-world Foo_Bar', str_capitalize('hello-world foo_bar'));
    eq('Hello World', str_capitalize('hello_world', '\r', false));
    eq('', str_capitalize(''));
    eq('journal_entries', snakeCase('JournalEntries'));
    eq('http_request', snakeCase('HTTPRequest'));
    eq('already_snake', snakeCase('already_snake'));
    eq('&lt;a href=&quot;x&quot;&gt;it&#039;s&lt;/a&gt;', htchars('<a href="x">it\'s</a>'));
    eq('a\nb', str_escape("a\nb"));
    eq('ab', str_escape("a\r\nb", true));
    eq('', str_escape(null));
    eq('a<br>b<br>c<br>d', htmlNewLine("a\r\nb\nc\\nd"));
    eq('a-b/', str_addHyphen('a/b', '/'));
    eq('abc', str_addHyphen('abc', '/'));
    eq(['John', ' Paul Smith'], __getSplitStr('John Paul Smith'));
    eq('bxd', strReplace('c', 'x', 'bcd'));
});

test('security helpers: random strings are secure-looking and different each time', function () {
    ok((bool) preg_match('/^[0-9A-Z]{11}$/', __randStr()));
    ok((bool) preg_match('/^[0-9a-z]{6}$/', __randStr(6, false)));
    ok(__randStr(20) !== __randStr(20));
    ok((bool) preg_match('/^[0-9a-z]{4,9}(-[0-9a-z]{4,9}){4}$/', tokenGen()), 'five groups');
    ok(tokenGen() !== tokenGen());
});

test('arrays: lists, searches, uniqueness, reduction', function () {
    eq('1,2,3,4', arrayToList([1, [2, 3], 4]));
    eq('', arrayToList([]));
    eq('a=1,b=2', __implode(['a' => 1, 'b' => 2]));
    eq('#1,#2', __implode([1, 2], false, ',', '#'));
    eq('1 | 2', __implode([1, 2], false, ' | '));
    ok(arraySearch('x', ['x']) && !arraySearch('y', ['x']) && !arraySearch('1', [1]) && arraySearch('1', [1], false));
    ok(searchMultiArray('b', [['k' => 'a'], ['k' => 'b']], 'k') && !searchMultiArray('z', [['k' => 'a']], 'k'));
    eq([[1], [2]], arrayUnique([[1], [1], [2]]));
    eq([1, 2], arrayUnique([1, 2, 1]));
    eq(5, arrayRand([5]));
    eq('', arrayRand([]));
    $two = arrayRand([1, 2, 3], 2);
    ok(is_array($two) && count($two) === 2 && $two[0] !== $two[1]);
    eq([1, 2], arrayMultiToSingle([['id' => 1], ['id' => 2]], 'id'));
    eq(['a' => 1, 'b' => 2], arrayReducer([['name' => 'a', 'value' => 1], ['name' => 'b', 'value' => 2]]));
    eq(['x' => 1], arrayReducer([['k' => 'x', 'v' => 1]], 'k', 'v'));
    eq([1 => 7.5, 2 => 1.0], add2dArray([['depart_id' => 1, 'total' => '5'], ['depart_id' => 1, 'total' => 2.5], ['depart_id' => 2, 'total' => 1]]));
});

test('arrays: sorting (by column, dates, numbers as numbers, text) and paging', function () {
    eq([['n' => 1], ['n' => 3]], sortArray([['n' => 3], ['n' => 1]], 'n'));
    eq([['n' => 3], ['n' => 1]], sortArray([['n' => 1], ['n' => 3]], 'n', SORT_DESC));

    $dates = [['d' => '2024-03-01'], ['d' => '2024-01-01'], ['d' => '2024-02-01']];
    eq(['2024-01-01', '2024-02-01', '2024-03-01'], array_column(sortMultiArray($dates, 'd'), 'd'));
    $nums = [['v' => '10'], ['v' => '9'], ['v' => '100']];
    eq(['9', '10', '100'], array_column(sortMultiArray($nums, 'v'), 'v'), 'numbers are not read as times');
    $text = [['v' => 'pear'], ['v' => 'apple']];
    eq(['apple', 'pear'], array_column(sortMultiArray($text, 'v'), 'v'));
    $desc = [['v' => 1], ['v' => 2]];
    eq([2, 1], array_column(sortMultiArray($desc, 'v', SORT_DESC), 'v'));

    eq([[1, 2], 1], paginateArray([1, 2], 10));
    [$pages, $count] = paginateArray(range(1, 25), 10);
    eq(3, $count);
    eq([10, 10, 5], array_map('count', $pages));
    eq([[1], 1], paginateArray([1], 0), 'size is clamped to 1');
});

test('arrays: JSON helpers', function () {
    eq(['a' => 1], parseArray('{"a":1}'));
    eq(['x' => ['b' => 2], 'y' => 'plain', 'z' => [1, ['q' => 3]]], parseArray(['x' => '{"b":2}', 'y' => 'plain', 'z' => [1, '{"q":3}']]));
    eq('text', parseArray('text'));
    eq(1, decodeJsonInArray(['k' => '{"a":1}'], 'k', 'a'));
    eq(['a' => 1], decodeJsonInArray(['k' => '{"a":1}'], 'k'));
    eq('', decodeJsonInArray(['k' => '{}'], 'missing'));
    eq('', decodeJsonInArray(null, 'k'));
});

test('dates: formatting, timezones, parsing', function () {
    boot_app(['config/app.php' => "<?php return ['timezone' => 'Africa/Harare'];"], boot: false);
    eq('2024-06-15 14:00', getDateTime('2024-06-15 12:00:00 UTC', 'Y-m-d H:i'), 'app timezone');
    eq('2024-06-15 12:00', getDateTime('2024-06-15 12:00:00 UTC', 'Y-m-d H:i', 'UTC'), 'explicit timezone');
    eq('2024-02-29', __fixDate('02/29/2024'));
    eq('2024-03-15', __fixDate('03-15-2024'));
    eq(date('Y-m-d'), __fixDate('garbage'));
});

test('dates: arithmetic and ranges', function () {
    boot_app(['config/app.php' => "<?php return ['timezone' => 'UTC'];"], boot: false);
    eq('2024-02-15 00:00:00', modifyDate('1M', '2024-01-15 00:00:00'));
    eq('2024-01-08', modifyDate('7D', '2024-01-15 00:00:00', 'sub', 'Y-m-d'));
    throws(InvalidArgumentException::class, fn() => modifyDate('1D', 'now', 'delete'), 'add');
    eq(3, dateDiff('2024-01-01', '2024-01-04')->days);
    eq('2024-02-29 00:00:00', getMonthLastDay('2024-02-10'));
    eq('2024-02-01', getFirstLast_monthDate(2, true, 2024));
    eq('2024-02-29', getFirstLast_monthDate('2', false, 2024));
    eq([['month' => '2024-01'], ['month' => '2024-02'], ['month' => '2024-03']], getMonthsInRange('2024-01-15', '2024-03-02'));
    eq([['year' => '2024', 'month' => '01']], getMonthsInRange('2024-01-15', '2024-01-20', false));
    $months = getYearMonths('m', ['x' => 1]);
    eq(12, count($months));
    eq(['m', 'x'], array_keys($months[0]));
    eq('Mar-2024', __useMonth('2024-03-05'));
});

test('dates: due-in wording', function () {
    $in = fn(string $a, string $b) => dateDiff($a, $b);
    eq('Due In 3 Days', __dueIn($in('2024-01-01', '2024-01-04')));
    eq('Due In 1 Day', __dueIn($in('2024-01-01', '2024-01-02')));
    eq('Due Today', __dueIn($in('2024-01-01', '2024-01-01')));
    eq('3 Days Overdue', __dueIn($in('2024-01-01', '2024-01-04'), 'overdue'));
    eq('1 Day Overdue', __dueIn($in('2024-01-01', '2024-01-02'), 'overdue'));
});

test('math: rounding, comparing, multiples, floats', function () {
    eq('105.00', __round('105'));
    eq('1.24', __round(1.239));
    eq('1.2', __round(1.24, 1));
    eq('0.00', __round('abc'));
    ok(__floats(0.1 + 0.2, 0.3) && !__floats(1, 1.001) && __floats(1, 1.001, 2));
    eq('yes', __compare(1, 1, 'yes', '=='));
    eq('', __compare('1', 1, 'yes', '==='));
    eq('x', __compare(5, 3, 'x', '>'));
    eq('', __compare(5, 3, 'x', '<='));
    ok(isMultiple(2000) && !isMultiple(1500) && !isMultiple(0) && !isMultiple(-1000) && isMultiple(2000.0) && isMultiple(10, 5));
    eq(1234.5, getFloat('1,234.50'));
    eq(12.0, getFloat(' 12 '));
    eq(0.0, getFloat('abc'));
});

test('math: money formatting with configurable formats and symbols', function () {
    boot_app(['config/money.php' => "<?php return ['formats' => ['EUR' => [2, ',', '.'], 'ZWL' => [0, '.', ' ']], 'symbols' => ['BWP' => 'P']];"], boot: false);
    eq('$ 1,234.50', __money(1234.5, '$'));
    eq('$1,234.50', __money('1234.5', '$', false));
    eq('($ 12.00)', __money(-12, '$'));
    eq('€ 1.234,50', __money(1234.5, '€'));
    eq('Z$ 1 235', __money(1234.5, 'Z$'));
    eq('US$ 5.00', __money(5, 'US$'));
    eq('US$', __symbolsCurr('USD'));
    eq('ZWG', __symbolsCurr('ZIG'));
    eq('P', __symbolsCurr('BWP'));
    eq('#', __symbolsCurr('XXX'));
});

test('html: jsonQuotes round-trips through an attribute; attribute helpers escape', function () {
    eq('{&#39;a&#39;:&#39;b&#39;}', jsonQuotes(['a' => 'b']));
    $quoted = jsonQuotes(['name' => "O'Brien", 'n' => [1, 2]]);
    lacks('"', $quoted, 'safe inside a double-quoted attribute');
    eq(['name' => "O'Brien", 'n' => [1, 2]], json_decode(str_replace('&#39;', '"', $quoted), true), 'the browser turns &#39; into a quote the front end can parse');
    eq('1,2', jsonQuotes([[1, 2]], true));

    ok(jsonValidate('{"a":1}') && jsonValidate('[]') && !jsonValidate('{a:1}') && !jsonValidate([]) && !jsonValidate(null));

    eq(" id='a&#039;b'", __attr(['id' => "a'b"]));
    eq(' disabled', __attr('disabled'));
    eq('', __attr(null));
    eq(' required readonly', __requiredAttr(true, false, true));
    eq('', __requiredAttr());
    eq('selected', __selectedValue(['a', 'b'], 'b'));
    eq('selected', __selectedValue('5', 5));
    eq('', __selectedValue('x', 'y'));
    eq('text-center', __textAlign(0));
    eq('text-start', __textAlign(1));
    eq('text-end', __textAlign(2));
    eq('text-center w-30', __textAlign(0, true));
    eq('text-start', __textAlign(2, true));
    eq('text-end', __textAlign(3, true));
});

test('html: __getImg() builds an escaped <img> from config(app.images_url)', function () {
    boot_app(['config/app.php' => "<?php return ['images_url' => '/img/'];"], boot: false);
    eq('<img class="img-fluid" src="/img/frontend/app/logo.png" alt="logo.png">', __getImg('frontend.app', 'logo.png'));
    eq('<img class="a&quot;b" src="/img/x/y&quot;.png" alt="y&quot;.png">', __getImg('x', 'y".png', 'a"b'));
});

test('misc: debug helpers print preformatted output; dd() ends the script', function () {
    ob_start();
    dump('a', ['b' => 1]);
    __prev('c');
    $out = ob_get_clean();
    has('<pre', $out);
    has('[b] => 1', $out);
    ok((new ReflectionFunction('dd'))->getReturnType()?->getName() === 'never', 'dd() never returns');
    ok((new ReflectionFunction('vd'))->getReturnType()?->getName() === 'never');
});
