# Porting the ERP to CastFramework: what was left out

Generated from the ERP branch `last-known-erpv4.8` (commit `5a4f421 2026-09-19`) by comparing it with CastFramework.

CastFramework contains **no ERP business logic**. This document lists everything ERP-specific that was *not* carried over, so that
if the ERP is ever rewritten on the framework (for example if the Laravel + Next.js migration does not work out) nothing has to be
rediscovered. Every entry says where it lives today and where it would plug in.

The listings below are generated from the source, so signatures and line numbers are exact for that commit.

## 1. How the ERP parts map onto the framework

| ERP part (v4.8) | Framework equivalent | Notes |
| --- | --- | --- |
| `Application`, `Boot/*`, `RegisterServices` | `Cast\App\Application`, `Boot\Bootstrap`, providers | Services are bound with `bind/singleton/set`; `app()->make("x")` replaces `app()->x()`. |
| `Core\Env` | `Cast\Core\Env` | Same `Env::get/bool/set`. `.env` is parsed once. `APP_ROOT_DIR` becomes `APP_BASE_PATH` (`/erp-app`). `APP_USE_PORT` is not needed. |
| `config/config.php` constants (`ROOT_PATH`, `VIEWS`, `ASSETS`, `DIR_PATH`, `DB_*`...) | `config(...)`, `base_path()`, `route()` | The framework does not define constants. An app that still wants them defines them in `config/`. |
| `Core\Router` | `Cast\Core\Router` | Adds `put/patch/delete/match/any`, 405 with `Allow`, `->withoutCsrf()`, `->use()`. **Middleware spec changed**: see section 9. |
| `Core\Middleware` (`auth`, `permissions`, `noAccess`, `failed`, `redirect`) | `Contracts\Guard`, `Http\Middleware\Authenticate`, `abort(403)` | Permission logic moves into an app `Guard` implementation. |
| `App\Middleware\Authentication` | `Authenticate` + an app `Guard` |  |
| `App\Middleware\AppModules`, `Core\Modules\ModuleManager` | An app middleware implementing `Contracts\Middleware` | Module registry is ERP-specific. |
| `App\Middleware\Fiscalisation`, `Fisc`, `ApiRecording`, `Mobile` | App middleware | ERP-specific. |
| `Core\Model` fragment builder | A trait in the app's own `Model extends Cast\Core\Model` | Section 4. |
| `Core\QueryBuilder`, `SafeModel` | `Cast\Core\QueryBuilder` (fixed `exists()`, added `count()` and multi-column `orderBy`) | `SafeModel` is an empty alias and is not carried over. |
| `App\Database\Database` | `Cast\Core\Database` | Reads `config("database")`; accepts the old `DB_CONN=mysql:` value. |
| `Core\Session` (generic part) | `Cast\Core\Session` | Section 5 lists what changed and what stayed ERP-side. |
| `Core\View` | `Cast\Core\View` (CastTemplate-based) | Legacy `.php` components still work; `.cast.php` components accept `<Tag />` syntax. |
| `Http\Controller` (13 methods) | `Cast\Http\Controller` + services | Section 2. |
| `Core\Maintenance\MaintenanceManager` (DB settings table) | `Core\Maintenance\MaintenanceManager` over a `MaintenanceStore` | Write a `MaintenanceStore` that reads the settings table to keep the ERP behaviour. |
| `Core\SystemUpdateManager` (releases.json + DB version) | `Contracts\Updater` + `CallbackUpdater` | Bind your own `updater` in the container. |
| `Helpers\Helpers.php` (3,045 lines) | `src/Helpers/*.php` by category + the app `custom/` folder | Section 3. |
| `FrontInterfaceController` + `/interface/*` | Ordinary controller actions returning a view with `type => "modal"` (SPA milestone) | Section 8. |

## 2. Base controller methods that were not carried over

From `src/App/Http/Controller.php`. Moved into the framework: `__construct`, `getModelInstance`, `formValidation` (now `Cast\Validation\Validator` + `LegacyRules`), `passwordValidate` (`PasswordPolicy`), `passwordAuth` (`Auth` + `UserProvider`), `saveData`/`patchData` (`ModelPersister`).

| Method | Line | What it does | Depends on | Where it would go |
| --- | --- | --- | --- | --- |
| `getSalesRep(string $name): array` | 267 | Employees whose `access` list contains the page name (sales reps). | - | ERP service (`EmployeesService`) |
| `transFormat(array $t, string $type): string` | 288 | Formats a transaction amount by document type (payments and credit notes negative), using the document currency. | currency | `TransactionPresenter` service |
| `transStatus(array $t, string $type): string` | 312 | Returns the status badge HTML (open, closed, paid, overdue, expired, void/credited) for a transaction type. | html/ui | `TransactionPresenter` service |
| `employees_byDepart(string\|int $dname): array` | 364 | Employees whose row contains a department name/id. | - | `EmployeesService` |
| `getPreferences(string $rowName): ?array` | 384 | The current user's saved preferences for a form, falling back to the defaults. | user | `PreferencesService` |
| `docSequencer(string $docName): string` | 390 | The active document sequence value for a document name. | models | `DocumentSequenceService` |

## 3. Helper functions that were not carried over

`src/Helpers/Helpers.php` defines 168 functions. 86 exist in the framework (same names); the other 82 are listed here, grouped by what they do.
Put them in the app's helper folder (`config("helpers.custom")`, e.g. `app/helpers/`), one file per group; each should be wrapped in `function_exists()`.

### Documents, numbering and transactions (11)

| Function | Line | What it does | Depends on |
| --- | --- | --- | --- |
| `__trxLen(int $default=10, $key='trx_len'): int` | 322 | Get the Transaction Length from the System Variables | settings |
| `__invoiceNo(string $str, string $separator='\|')` | 327 | - | - |
| `__taxName(string $str, string $prefix='Tax'): string` | 773 | A helper function to prefix 'Tax' or Other prefix on a string value. | settings |
| `getTrxPageState(string $key, bool $clear=true): array` | 822 | Helper function to get the transaction page state from the session. This works for invoices, receipts when a user converts a transaction from one type to another. | session, user |
| `documentName(string $docName, string $default=''): string` | 877 | Get the document name with fiscal and tax information. | settings |
| `saveDocument(string $data, string $doc='invoice'): string` | 1775 | Saves a Document in the resources | - |
| `__docsequence(array $seq, int $number=1): int\|string` | 1853 | - | - |
| `__lineItem(array $item, int $i, string $key): string` | 2042 | - | - |
| `formatDocNo(string $string): string` | 2340 | Format document number | - |
| `__txnLink(string $url, string $urlParam, string $paramId='no'): string` | 2587 | - | - |
| `newDocNo(string $doc): string` | 2597 | Get Next Document Number. Takes current number and increments with 1 | - |

### Tax, VAT, fiscal and voiding (11)

| Function | Line | What it does | Depends on |
| --- | --- | --- | --- |
| `taxValue(array $item): string` | 992 | - | settings |
| `taxInc()` | 1054 | - | settings |
| `__getFiscalData(mixed $jsonFiscalData, ?string $key='globalNumber'): arr…` | 1345 | Get Fiscal Data from the jsonFiscalData | - |
| `exVatCalculator(float\|int\|string $amount=0, string $type=''): float` | 1362 | - | - |
| `getVatReturnDueDate(string $docDate, string $setup='bi-monthly', int $du…` | 1441 | Calculates the VAT Return Submission Due Date for a given invoice/document date. | - |
| `getVatPeriodString(string $docDate, string $setup='bi-monthly'): string` | 1484 | Returns formatted VAT period string (e.g. Jan – Feb 2026). | - |
| `validateInvoiceVoidable(string $docDate, string $setup='bi-monthly', int…` | 1515 | Validates whether an invoice can be voided and credited under VAT Act rules and 60-day limit. | - |
| `renderVoidScenarioMatrixHtml(array $voidCheck, string $voidReason='', bo…` | 1575 | Generates an HTML scenario matrix table for SweetAlert2 (Swal.fire) popups. | html/ui |
| `renderInvoiceVoidPreviewHtml(array $invoice, array $voidCheck, string $v…` | 1668 | Render the pre-commit void ledger-impact preview using the void-alert component. | html/ui |
| `__getTaxAmt(array $tax, float\|string $amt): float` | 1819 | - | - |
| `renderVoidAlertCard(array $scenarios, bool $showGlImpact=true, bool $com…` | 2997 | Render void alert card HTML. | html/ui |

### Currency and amounts (depend on ERP settings) (8)

| Function | Line | What it does | Depends on |
| --- | --- | --- | --- |
| `__currentDate(bool $forceDate=false, string $format='d-M-Y'): string` | 438 | Get the current date in a specified format. | controller |
| `__curr(bool $lcase=false, bool $returnSymbol=false): string` | 1269 | Return a currency | settings |
| `__getAmtByType(array $t): array` | 1304 | - | currency |
| `__getDiscountAmt(int\|string $type, float\|string $value, float\|string $am…` | 1837 | - | - |
| `__getAmount(float\|string\|null $amt, string $curr, bool $symbol=true): st…` | 2272 | Helper Function that returns a formatted money amount. | currency |
| `forNonBaseCurr(array $array, string $arrayKey, float\|int $amt): float` | 2500 | - | settings, currency |
| `nonBaseCurr(float\|int\|string $value, string $curr): float\|int` | 2515 | - | settings, currency |
| `getTotal_nonBaseCurr(array $data, string $key='amount'): float\|int` | 2554 | - | - |

### Banks, company and addresses (7)

| Function | Line | What it does | Depends on |
| --- | --- | --- | --- |
| `__getBank(string $col, string\|int $value)` | 777 | - | settings |
| `__getBanks(string $col, string $value): array` | 790 | - | settings |
| `jobCardShipAdd(string $address): string` | 1355 | Get Shipoping Address from the jobcard | - |
| `__coa($account, string $key='account')` | 1808 | Returns the Chart of Account | settings |
| `__getAddressName(string $address): string` | 2689 | Get the name of the address form a full address string | - |
| `__addressDetails(array $details=[]): string` | 2702 | Get the Textarea Address information Correctly formated for textarea. | - |
| `__displayBankList(string \| null $curr=null): array` | 2720 | - | - |

### Jobcards and fleet (1)

| Function | Line | What it does | Depends on |
| --- | --- | --- | --- |
| `getFleetData(array $data, string\|int $key, string\|int $iteretor, string …` | 2913 | - | - |

### Reports, ledgers and period math (12)

| Function | Line | What it does | Depends on |
| --- | --- | --- | --- |
| `getDateOrMonthRange(string $startDate, string $endDate): array` | 1689 | Generate date list or month list based on date range | - |
| `calcPercentages(float $currentValue, float $prevValue, $isMoney=true): s…` | 2093 | Calculate Percentage Change from old to current value | html/ui, currency |
| `addArray(array $data, ?array $fields=null, string $amtKey='amount'): flo…` | 2121 | - | currency |
| `matchFields(array $fields, array $data, array $dataToMatch, array $match…` | 2146 | - | - |
| `matchAddPair(array $data, array $dataToMatch, array $matchQuery, string …` | 2173 | - | - |
| `getB(mixed $g, mixed $key, string $comparator): bool` | 2196 | - | - |
| `trimWrongYear(array $data, string $key='month'): array` | 2220 | - | - |
| `mergeDatesArray(array $data, int $periodLength, array $dates, string $va…` | 2356 | Merges the given data with the dates array, ensuring that all dates are represented. | currency |
| `getTwelveMonths(array $data, array $data2=[], string $account='income', …` | 2464 | - | settings, currency |
| `getTotal(array $data, string $key='number', bool $withNonBaseCurr=true):…` | 2538 | Get Total Amount of a given key in an array. | currency |
| `addMonthsInRange_ToData(array $monthsFromRange, array $data, string $cur…` | 2869 | - | - |
| `checkReportDurationLimit(string $startDate, string $endDate, int $years=…` | 2976 | Check if the date range selected by the user exceeds a specific number of years. | - |

### Notifications, audit and background work (4)

| Function | Line | What it does | Depends on |
| --- | --- | --- | --- |
| `sendMaintanance($msg='Module currently under maintanance. Please contact…` | 849 | - | - |
| `createNotification(string $uid, string $type, string $title, string $mes…` | 2945 | Create a notification for a specific user. | - |
| `broadcastNotification(string $type, string $title, string $message, ?arr…` | 2957 | Broadcast a notification to all active users. | models |
| `audit_log(string $eventType, string $module, string $description, ?strin…` | 3021 | Record an audit log event in the ERP system. | - |

### Settings, users and permissions (7)

| Function | Line | What it does | Depends on |
| --- | --- | --- | --- |
| `__unlockGate(string $gate, int $ttlSeconds=600): void` | 413 | Unlock a named "password gate" for the current session, for a short TTL. | session |
| `__gateUnlocked(string $gate): bool` | 425 | Check whether a named "password gate" is currently unlocked and not expired. | session |
| `__filterShowInactive(bool $force=false): bool` | 459 | - | controller |
| `__getOptions(array $data, string\|int $value, string\|int $label, bool $da…` | 472 | Get Options for Select Input | - |
| `__selectOptions(array $array)` | 494 | Get Options for Select Input | - |
| `__sysVars(string $arrayName, ?string $key=null): mixed` | 752 | Gets the value of a given key in a Session array. | settings, session |
| `useTimestamps(array $data, bool $time=false): array` | 2660 | - | - |

### Request and form data parsing (10)

| Function | Line | What it does | Depends on |
| --- | --- | --- | --- |
| `getUri(): string` | 514 | - | - |
| `mysql_escape(mixed $inp): mixed` | 582 | - | - |
| `test_input(array\|string $data): array\|string\|null` | 599 | Test the Posted Input values for any code, strip to avoid any SQL Injection | - |
| `__active(array\|string $data, string $value, string $key='tab')` | 1078 | A Helper Function to check if a value is active. | - |
| `decode_serialData(string $serializedDataString, string $separator='&amp;…` | 1193 | - | - |
| `getListParams(?array $data, string $key='params'): array` | 1888 | Get All Params in a Li params | - |
| `getArrayParams(array $params, string $key='name', string $value='value')…` | 1983 | - | - |
| `__interface(array $data, string $sectionName, bool $decode=true): array\|…` | 2079 | - | - |
| `__ifGet(string $key, string\|int $value, string\|int $returnVal, string\|in…` | 2238 | - | - |
| `checkSelectedOption(array $data, string $key): bool` | 2920 | - | - |

### UI rendering helpers (7)

| Function | Line | What it does | Depends on |
| --- | --- | --- | --- |
| `getTemplateFile(string $template): string` | 82 | Get the Template File from the resources/templates directory. | - |
| `get_git_version(bool $short=true): string` | 543 | Get the current git version (commit hash) of the application | - |
| `str_remove_plural(string\|null $str): string` | 699 | - | - |
| `fixDir(string $path): string` | 975 | Fix a directory path to the correct format for the OS | - |
| `__getColor(string $colorScheme): string` | 1063 | Gets and returns the color from Theme Provider | session |
| `__eMsg(mixed $e): string` | 1996 | - | - |
| `__url(int $key): string` | 2052 | Get Requested Url of the current Page. | - |

### Other (4)

| Function | Line | What it does | Depends on |
| --- | --- | --- | --- |
| `__autocomplete(bool $force=false, string $value='on'): string` | 451 | Returns the autocomplete attribute for an input field based on the users preference. | controller |
| `trimString(mixed $str)` | 603 | - | - |
| `testData(mixed $var)` | 636 | - | - |
| `serverPagination(array $request): array` | 2814 | Server-side pagination helper function. | - |

Helpers that exist in both places and behave the same (generic ones): `app`, `env`, `dir_scan`, `views`, `render404`, `Component`, `__requiredAttr`, `__includes`, `__modules`, `__csrf`, `__verifyCsrf`, `route_to`, `route`, `checkRouteParams`, `_access`, `_checkAccess`, `__getConfig`, `useConfig`, `dd`, `dump`, `vd`, `__prev`, `hashPassword`, `verifyPassword`, `__dueIn`, `file_control`, `app_version`, `str_capitalize`, `__ucwords`, `__ucfirst`, `htchars`, `str_escape`, `__invalidFeedback`, `__getUser`, `sendAlert`, `__busyLoader`, `__getAlerts`, `clearState`, `__getSess`, `jsonQuotes`, `jsonValidate`, `parseArray`, `decodeJsonInArray`, `__round`, `__floats`, `__compare`, `__getImg`, `__attr`, `__selectedValue`, `arrayToList`, `__getSplitStr`, `getPost`, `checkPostParams`, `__money`, `__symbolsCurr`, `getDateTime`, `__fixDate`, `dateDiff`, `modifyDate`, `getMonthLastDay`, `getFirstLast_monthDate`, `validateParam`, `__randStr`, `tokenGen`, `snakeCase`, `htmlNewLine`, `sortArray`, `sortMultiArray`, `isMultiple`, `getFloat`, `getMonthsInRange`, `getYearMonths`, `__useMonth`, `add2dArray`, `strReplace`, `str_addHyphen`, `checkValidity`, `__textAlign`, `__implode`, `searchMultiArray`, `arraySearch`, `arrayUnique`, `arrayRand`, `arrayMultiToSingle`, `paginateArray`, `arrayReducer`.

Behaviour differences in the ported helpers:

- `views()` still echoes and returns `true`; `$this->view()` in a controller returns a `Response`.
- `__verifyCsrf()` now **throws** a 419 `HttpException` instead of printing JSON and `die`.
- `route_to()` returns the redirect `Response` in the CLI and sends it (then exits) in the web.
- `__getConfig($name)` returns `[]` for a missing key (the original returned the whole JSON file).
- `__randStr()`/`tokenGen()` use `random_int()` (the original used `array_rand()`).
- `getPost()` returns the body sanitised by `config("request.sanitizer")`. To keep the old behaviour set it to the ERP's `test_input()`.
- `sendAlert()` keeps the `{type, msg, callback}` JSON shape; new code should use `Response::success/error` (`{status, msg, data}`).
- `__invalidFeedback()` reads the flashed `input_errors` (the old one read the persistent session key).

## 4. `Core\Model`: methods that were not carried over

`src/Core/Model.php` has 75 methods. 14 have a framework counterpart (`getAll`, `getOne`, `exists`, `updateColumns`, `setActive`, `deleteRows`, `paginate`, transactions, `query`, `table`, `raw`).

**Legacy fragment builder** (`where/and/or/insert/update/execute...`): keep it in the app as a trait on `App\Core\Model extends Cast\Core\Model`, so no ERP model changes. The methods marked *interpolating* are listed in AGENTS.md as deprecated.

| Method | Line | Kind |
| --- | --- | --- |
| `bind(mixed $value): string` | 41 | legacy builder |
| `init(): ?PDO` | 53 | legacy builder |
| `conn(): PDO\|null` | 60 | legacy builder |
| `factory(?string $table=null): Model` | 68 | legacy builder |
| `sql(string\|array $sql, array\|string $params=[], ?string $fetchMode='fetchAll'): …` | 118 | legacy builder |
| `executeSql(string\|array $sql, array\|string $params=[], ?string $fetchMode='fetch…` | 123 | legacy builder |
| `getAutoIncrement()` | 128 | accounting / domain |
| `select(array $columns, bool $join=false)` | 136 | legacy builder |
| `all(): string` | 146 | legacy builder |
| `columns(array $columns, bool $join=false): string` | 157 | legacy builder |
| `distinct(array $columns, bool $join=false): string` | 170 | legacy builder |
| `count(string $column='id'): string` | 182 | legacy builder |
| `selectSum(array $fields, string $col, string $start, string $end): array` | 195 | legacy builder (interpolating, deprecated) |
| `sum(array\|string $sumCols, array $columns=[], string $mathOperator='-', string $…` | 214 | legacy builder |
| `getSumColumns(array\|string $sumCols, string $mathOperator='-', string $alias='nu…` | 231 | legacy builder |
| `join(string $table2, string $t1, string $t2='id'): string` | 249 | legacy builder |
| `where(string $column, mixed $operatorOrValue=null, mixed $value=null): string` | 278 | legacy builder |
| `and(string $column, mixed $operatorOrValue=null, mixed $value=null): string` | 309 | legacy builder |
| `multiAnd(array $cols): string` | 326 | legacy builder |
| `whereBetween(string $start, string $end, string $dateColumn='created_at'): strin…` | 343 | legacy builder |
| `or(string $column, mixed $operatorOrValue=null, mixed $value=null): string` | 361 | legacy builder |
| `order(string $column='id', string $direction='ASC', bool $isMulti=false): string` | 384 | legacy builder |
| `andOrder(string $column='id', string $direction='ASC'): string` | 394 | legacy builder |
| `group(string $column='id'): string` | 403 | legacy builder |
| `limit(int $limit=10): string` | 412 | legacy builder |
| `insert(array $columns, bool $creator=true): string` | 438 | legacy builder |
| `update(array $columns, bool $updater=true): string` | 465 | legacy builder |
| `delete(): string` | 481 | legacy builder |
| `sqlQuery(string $sql, bool $asArray=true): array\|string` | 497 | legacy builder (interpolating, deprecated) |
| `useFilterQuery(array $filters, bool $where=false): string` | 515 | legacy builder |
| `countAll(): int` | 535 | accounting / domain |
| `countAllBtwn(string $start, string $end, int $active=1, int $deleted=1)` | 552 | accounting / domain |
| `execute(array $query, string\|array\|null $execute=null): bool\|array\|int` | 567 | legacy builder |
| `lastInsertRecord(?string $table=null): array\|bool` | 593 | accounting / domain |
| `lastestRecords(int $limit=1, string $where='active=1', ?string $table=null): arr…` | 603 | accounting / domain |
| `getPagination(array $data, int $perPage=10, int $page=1, ?int $totalCount=null):…` | 618 | accounting / domain |
| `getPaginationBtwn(array $data, string $start, string $end, int $perPage=10, int …` | 659 | accounting / domain |
| `latestId(): int` | 702 | accounting / domain |
| `overRecords(int $id, string $over='prev', int $limit=1, ?array $and=null): array…` | 714 | accounting / domain |
| `fieldset(array $filtersList, string $column, ?string $tableName=null): string` | 733 | legacy builder (interpolating, deprecated) |
| `getAllBtwn(string $start, string $end, ?array $and=null, string\|int $active=1, ?…` | 779 | accounting / domain |
| `getDetails(int\|string $id)` | 810 | accounting / domain |
| `getName(int\|string $id, string $key='name')` | 821 | accounting / domain |
| `withFilter(array $filters, bool $whereOption=true): string` | 838 | accounting / domain |
| `docNum(string $prefix): string\|array` | 842 | accounting / domain |
| `getListTotalsBtwn(array $mathColsAlias, array $columns, string $start, string $e…` | 873 | accounting / domain |
| `getListTotals(array $mathColsAlias, array $columns, string\|int $value, string $c…` | 897 | accounting / domain |
| `transactions(string\|int $value, string\|int $col='cust_id', int\|string\|null $limi…` | 922 | accounting / domain |
| `updateFiscal(string $docNum, mixed $data)` | 965 | accounting / domain |
| `getAccTotal($isNegetive=false)` | 988 | accounting / domain (interpolating, deprecated) |
| `getTotalBtwn(string $column, string $start, string $end)` | 1008 | accounting / domain (interpolating, deprecated) |
| `sumAllBtwn(string $column, string $start, string $end, array $withCols=[], ?stri…` | 1016 | accounting / domain |
| `getTotal(string $column, ?string $sql=null)` | 1028 | accounting / domain |
| `getAging(): array` | 1038 | accounting / domain |
| `docExists(int\|string $doc, string $col='doc_num'): int\|string` | 1077 | accounting / domain |
| `exact(array $data, bool $hasJobcard=true, string $dateName='doc-date', string $s…` | 1102 | accounting / domain |
| `doneFunction(mixed $done): array\|int\|string` | 1116 | accounting / domain |
| `itemExists(string\|int $value, string $col='id', string\|null $sql=null): bool` | 1123 | accounting / domain |
| `getBal(int\|string $cid, $col='cust_id', $negetiveBal=false)` | 1133 | accounting / domain (interpolating, deprecated) |
| `openingBal(int\|string $cid, string $start, string $curr, $asName='total', $sumCo…` | 1145 | accounting / domain (interpolating, deprecated) |
| `searchTransactions(int\|string $value, $col='doc_num', $limit=10)` | 1151 | accounting / domain |

## 5. `Core\Session`

| Method | Line | In the framework |
| --- | --- | --- |
| `init(): void` | 28 | `init` |
| `regen(): void` | 34 | `regenerate` |
| `csfrToken(): string` | 38 | `csrfToken` (renamed; the old name was misspelled) |
| `set(string $key, mixed $value): void` | 45 | `set` |
| `get(string $key): mixed` | 50 | `get` |
| `clear(string $key): void` | 58 | `clear` |
| `set_userData(string $key, mixed $value): void` | 65 | **not carried over**: ERP settings/state storage |
| `get_userData(string $key): mixed` | 69 | **not carried over**: ERP settings/state storage |
| `set_settings(string $key, mixed $value): void` | 78 | **not carried over**: ERP settings/state storage |
| `set_state(string $key, mixed $value, $storageKey='state'): void` | 82 | **not carried over**: ERP settings/state storage |
| `get_settings(string $key): mixed` | 87 | **not carried over**: ERP settings/state storage |
| `set_messages(string $key, $value): void` | 96 | replaced by `flash` / `getFlash` / `peekFlash` |
| `get_message(string $key): mixed` | 107 | replaced by `flash` / `getFlash` / `peekFlash` |
| `get_state(?string $key, $storageKey='state'): ?array` | 117 | **not carried over**: ERP settings/state storage |
| `clear_message(string $key, ?string $message_array=null): void` | 132 | replaced by `flash` / `getFlash` / `peekFlash` |
| `destroy(array $keys): void` | 143 | `destroy` |
| `unset(string $key1, mixed $key2=null)` | 153 | **not carried over**: ERP settings/state storage |

The ERP re-adds the settings/state methods with `class Session extends Cast\Core\Session`.

## 6. Middleware

| Class | Public methods | Purpose |
| --- | --- | --- |
| `ApiRecording` | `create`, `update` | Records API calls. |
| `AppModules` | `handle`, `verify`, `moduleActive` | Blocks routes of modules not activated for the company. |
| `Authentication` | `handle`, `isOnboardingCompleted`, `canPerformSetup`, `authToken` | Login/guest guard (`authguard`) and redirects. |
| `Fisc` | - | Fiscal stub. |
| `Fiscalisation` | `createInvoice`, `creditInvoice`, `debitInvoice`, `fiscalData` | Fiscal device integration for invoices. |
| `Mobile` | `handle` | Mobile routes guard. |
| `Core\Middleware` | `auth`, `redirect`, `route`, `middleware`, `roleAccessPerms`, `permissions`, `noAccess`, `failed` | Permission checks, access-denied output, redirect. Becomes a `Guard`. |

## 7. Services, managers and other classes

| Class | File | Public methods |
| --- | --- | --- |
| `CompanyEventNotificationService` | `App/Services/CompanyEventNotificationService.php` | `checkAndDispatch` |
| `DoubleEntryService` | `App/Services/DoubleEntryService.php` | `isAccountingModuleActive`, `resolveCoaId`, `syncInvoice`, `syncBill`, `syncExpense`, `syncPayment`, `syncJournal`, `syncCreditNote`, `syncVoidedInvoice`, `restoreVoidPayments`, `syncDeposit`, `syncAllDeposits`, `syncPayroll`, `syncPayrollPayment`, `syncAsset`, `syncAllAssets`, `deleteDocumentLedger`, `reconcileBalances`, `syncVatReturnPayment` |
| `EmployeesService` | `App/Services/EmployeesService.php` | `getEmploymentInfo`, `getPayrollInfo`, `getPayrollComponents`, `getDepartmentJobs`, `getFullName`, `getAge`, `getGender`, `getDepartment`, `getJobTitle`, `getSalary`, `getHireDate`, `getPhone`, `getEmail`, `getPhoto` |
| `FleetNotificationService` | `App/Services/FleetNotificationService.php` | `checkAndDispatch` |
| `JobcardService` | `App/Services/JobcardService.php` | `clientList`, `writable` |
| `OptionsService` | `App/Services/OptionsService.php` | `getPrintOptions` |
| `PaymentMethodService` | `App/Services/PaymentMethodService.php` | `methodsOptions` |
| `ComponentsService` | `App/Services/Payroll/ComponentsService.php` | `clientList`, `printableList` |
| `RbacService` | `App/Services/RbacService.php` | `getGroupedPermissions`, `isProtectedRole`, `isProtectedUser` |
| `Services` | `App/Services/Services.php` | `run` |
| `ShipAddressService` | `App/Services/ShipAddressService.php` | `getAddessName`, `makeDefaultName` |
| `TaskNotificationService` | `App/Services/TaskNotificationService.php` | `checkAndDispatch`, `notifyAssigned`, `notifyCompleted` |
| `TaxNotificationService` | `App/Services/TaxNotificationService.php` | `checkAndDispatchVatNotifications`, `getTaxAuthorizedUserUids`, `getVatPeriodsForYear` |
| `UpcomingEventsService` | `App/Services/UpcomingEventsService.php` | `getUpcoming` |
| `MaintenanceManager` | `Core/Maintenance/MaintenanceManager.php` | `getStatus`, `isMaintenanceActive`, `scheduleMaintenance`, `activateMaintenance`, `deactivateMaintenance`, `canBypassMaintenance` |
| `SystemUpdateManager` | `Core/SystemUpdateManager.php` | `getLatestCodeVersion`, `getDatabaseVersion`, `getReleases`, `getWalkthroughs`, `getMigrationFiles`, `getPendingMigrations`, `run`, `autoRun` |
| `ModuleManager` | `Core/Modules/ModuleManager.php` | `isModuleActive`, `syncSession`, `toggleModule`, `applyTier`, `getAllModulesWithStatus`, `getTiers` |
| `DbQueue` | `Core/DbQueue.php` | `runInQueue` |
| `AppController` | `App/Http/AppController.php` | `set_modules`, `set_app_settings`, `set_advanced_settings`, `set_company`, `set_bank` |
| `QueueService` | `App/Database/QueueService.php` | `runInQueue` |

There are 88 models in `src/App/Models/`. None were carried over.

## 8. Front controller and front-end code

`FrontInterfaceController` serves modal interfaces (`{modalClass, form, content}`) from `POST /interface/*`. In the framework these become normal actions that return a view with `type => "modal"` (SPA milestone). Endpoints today:

| Route | Action |
| --- | --- |
| `POST /interface/track-state` | `track_state` |
| `POST /interface/coa` | `coa` |
| `POST /interface/fleet` | `fleet` |
| `POST /interface/jobcard` | `jobcard` |
| `POST /interface/customers` | `customers` |
| `POST /interface/suppliers` | `suppliers` |
| `POST /interface/employees` | `employees` |
| `POST /interface/products` | `products` |
| `POST /interface/billables` | `billables` |
| `POST /interface/ship-address` | `ship_address` |
| `POST /interface/payments` | `payments` |
| `POST /interface/service` | `service_request` |
| `POST /interface/import-guide` | `import_guide` |
| `POST /interface/system-modules` | `system_modules` |
| `POST /interface/system-updates` | `system_updates` |
| `POST /interface/system-advanced` | `system_advanced` |

JavaScript that is ERP-specific and not part of the framework client:

- `hooks.module.js` (2759 lines)
- `app.module.js` (1163 lines)
- `mobile.module.js` (143 lines)
- `notifications.module.js` (219 lines)
- `tour.module.js` (277 lines)
- `password-overlay.module.js` (89 lines)
- `rightModal()` in `hooks.module.js`: opens `/interface/{name}`, injects the content, initialises `selectable`, date pickers and validation, binds the form submit.

## 9. Configuration, routes and resources

**Environment keys** (names only) used by v4.8, and how they map:

| Key | Framework |
| --- | --- |

**Config files:** `config.json` (quick links, navigation, currencies: read with `__getConfig()`), `colors.php`, `route-list.php`, `session.php`, `releases.json`, `walkthroughs.json`, `countries.json`.

**Route files** (one per domain): `accounting.php`, `api.php`, `customers.php`, `hrm.php`, `mobile.php`, `notifications.php`, `reports.php`, `tools.php`, `transactions.php`, `vms.php`, `web.php`. They load unchanged through `RouteProvider`, apart from the middleware spec change below.

**Views:** `layouts/`, `components/` (including `dependences/links` and `dependences/scripts`, the CSS/JS loaders), per-module folders with `{module}.cast.php` shells and `partials/`.

### Changes needed in route files and middleware

- The group middleware spec was `[Class::class, "method", "arg"]` (calls `(new Class)->method($arg)`) or `[Class::class, "arg"]` (calls `handle($arg)`). The framework spec is `[Class::class, ...$args]` and always calls `handle(Request $request, ...$args)` on a class that implements `Cast\Contracts\Middleware`. `Authentication::authguard` becomes `Authenticate` with the guard name as the argument.
- `->middleware([...])` on a route still takes permission slugs (any one is enough); they are checked with the bound `Guard::can()`.
- A handler still receives its route params, then the merged input array as the last argument.
- CSRF is checked centrally for POST, PUT, PATCH and DELETE (it already was for POST), with the same token sources.
- Functions the framework already defines are wrapped in `function_exists()`. If the ERP's own helper file declares the same function without a guard, PHP stops with "Cannot redeclare": delete the duplicate or wrap it.

