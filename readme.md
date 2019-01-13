# PWMC
PWMC stands for PHP Window Manager Controller and is a PHP interface for using wmctrl on your Linux machine.

What PWMC gives you that wmctrl doesn't is an object representing each window that you can save and interact with, a way to initiate a program and instantly get the object representing it's window and the possibility to give each window a name that is remembered between script instances for easier access to the windows.

## Installation and requirements
Installing PWMC is as easy as just downloading it.

For it to run you need to use it with PHP7 and have wmctrl installed.
A few methods also require xdotool and for your graphic card to be compatible able with xrandr. Except from those methods though PWMC shuld run fine without them (If a method needs any of those it will be specified in that methods documentation)

## How to use
To use PWMC first include `setup.php` which will give you a very basic autoloader for the projects classes then just use it as you would any other PHP classes.

Lets say you have two monitors and want to open a terminal on each. One placed a bit in and the other in fullscreen and start a tail on a log on it you could do
```PHP
<?php
include 'path/to/PWMC/setup.php';

$term1 = new Window('gnome-terminal');
$term1->move(100, 100);

$term2 = new Window('gnome-terminal');
$term2->fullScreen(1)->type('tail -f /var/log/somelog')->press('Return');
```

Or say you are using Firefox to monitor things but at some point you need to open a new tab to show a different statistic you can name them as so.
```PHP
<?php
include 'path/to/PWMC/setup.php';

$ff1 = new Firefox('http://example.com/screen1');
$ff1->fullScreen(0);
$ff1->name = 'screen1';

$ff2 = new Firefox('http://example.com/screen2');
$ff2->fullScreen(1);
$ff2->name = 'screen2';
```

And then later use it in another script
```PHP
<?php
include 'path/to/PWMC/setup.php';

PWMC::get('screen2')->newTab('http://example.com/screen2/statistics');
```

## How it works
Each time you instantiate a `Window` object a system call will execute the command given as an argument and the object will go into "wait" mode where it will remain until it has been associated with a window ID, every method called on the object will be stacked and only executed after the object has gone out of "wait".

A window is considered associated if the console command `ps -o comm --no-headers -p` followed by the window's PID returns the same command as was used to instantiate the object. In cases where this will not happen you should use `PWMC::search([search args], true)` instead and then start the process with `PWMC::start()`

To avoid confusion of association a new `Window` object with the same command as a previous object will not be executed before the previous one has gone out of "wait"

In order to make sure that all commands in the script is actually executed the script itself will go into "wait" upon reaching the end until all objects in "wait" has been resolved or ten seconds has passed since the last action.

## Classes and methods
Following is a list of all the projects classes. However, I will only list public properties and methods as well as protected ones that are relevant for extending the classes into specific window classes as this guide is only intended as a general guide in using PWMC and not a guide for developing it. If you want to know more of the inner workings you're welcome to read the code.

### Class: PWMC
As the name implies this is the main class that all the others inherits from. This class also serves as the namespace for all of PWMC's utility functions.

#### const array COMM_MAP
This is an associative array that maps different commands to different classes. This is used to make PWMC associate a window with the right class, for instance, every window that is initiated using the command `firefox` will be a instance of `Firefox` rather than just `Window`

If a command is not found in this array the script will default to `Window`

#### public \_\_construct(string $cmd)
Doesn't do much besides defining `__construct` for the children and makes sure that the necceray setup has been run for PWMC to work.

#### public static int millitime()
Utility method to get the current unix time in milliseconds as that is the time measurement in PWMC but PHP does not provide a method for that.

#### public static mixed arrayPluck(array &$array, mixed $key)
Utility method to extract (and unset) a specific value from array based on key.

Will return `null` if key is not found

#### public static Window|null search(array $filter [, bool $wait = false])
Searches the currently existing windows and returns the associated object of the first match against the filter. Or null if no match was found.

`$filter` is an associative array that contains one or more properties to search for in the window list. All supplied properties must be a match in order for the filter to be a match.

A filter can contain the following items:
- 'title' A regular expression that will be tested against the title of a window.
- 'pid' An integer representing the process ID of the window.
- 'wid' An integer representing the window ID of the window.
- 'comm' A string that is tested against the result of running `PWMC::getComm($pid)`

If `$wait` is set to `true` this method will always return an object. If said object does not have an associated window it will go into "wait" in much the same way as if you had made a new instance of `Window`. Unlike a new instance though this will not attempt to start any program and you must do so manually using `PWMC::start($cmd)`

Be aware if you're using `$wait` that any window started with a new instance will take precedence and thus the following code.
```PHP
<?php
$terminal = PWMC::search(['comm' => 'gnome-terminal'], true);
new Window('gnome-terminal');
```
Would result in a new object associated with the terminal window and `$terminal` still being unassociated.

#### public static void block(array $filter [, callable $callback = null [, array $args]])
Will catch any new window that matches `$filter` and stop it from getting associated with an waiting object. This is useful to deal with alerts or other popups.

See `PWMC::search()` for an explanation on how to use `$filter`

If `$callback` is set the provided callback will be called every time a window is blocked parsing an associated instance of `Window` as its first argument.

Anything contained in `$args` will be passed to the callback after the first argument.

Please be advised that a block will remain after blocking a window and continue to block any following matched windows. Furthermore also be advised that a block will not cause the script to wait upon exiting, so any window being opened after the script has finished will not be blocked.

#### public static Window|null get(string $name)
Returns a named window set by writing `$window->name = 'some name'`  
Or null if no window has that name.

Names are saved between instances of PWMC

#### public static array<name, Window> getNames()
Returns an associative array containing all currently existing named windows with the name as key.

#### public static int start(string $cmd)
Executes `$cmd` in background and returns the process ID

#### public static string getComm(int $PID)
Executes `'ps -o comm --no-headers -p '.$PID` and returns the result. Which in most cases should be the command used to start the process.

#### public static array getMonitors()
Returns information about the machines connected monitors. This is used to calculate window placement in the methods that allows you to specify a monitor.

This method will try to give the monitors logical numbers starting with the top left monitor, numbering it as 0.

Be advised though that this method relies on `xrandr` and does not work on all builds.  
Furthermore this is a slow operation and thus the result is chased meaning that changes made to the monitors while a script is running will not be taken into account.

#### public static void doin(callable $callback, int $time [, array $args])
Calls `$calback` after `$time` milliseconds has passed.

This works a lot like calling `sleep()` with the exception that it will not halt the script execution making the overall script run faster.

However as a result of that we can not guarantee that only that amount of time has passed, only that at least that amount of time has.

`$args` is an array of arguments to pass to `$callback`

#### protected static function scan()
This is the main worker of PWMC. It scans the currently existing windows and updates associations and data accordingly.

You might need this if you're extending `Window`, but most likely you don't.

### Class: Window
This is the main class for interacting with a window, and the one you will want to extend if you want to write a class for a specific program.

#### Constants
A window can be in four states which is described by the four state constants.
1. STATE_INIT Means that the class has been instantiated but has not yet been associated with a window, nor has any methods been called on it.
2. STATE_WAITING Means that the object has still to be associated with a window, but one or more methods has been invoked and is now waiting for the object to change state to ready before running them.
3. const STATE_READY The object has been associated with a window, and everything invoked on it will happen immediately.
4. const STATE_CLOSED The window that the object was associated with has been closed.

#### Properties
While `Window` holds no public properties the following properties are made available through magic methods.

- state (get) The state of the object, will be one of the STATE_* constants
- wid (get) The window ID of the objects window
- pid (get) The process ID of the objects window
- comm (get) The command used to spawn the objects window
- title (get / set) The title of the objects window
- name (set) The name used to identify the object in a later instance of PWMC

#### public \_\_construct(string $cmd)
The `$cmd` passed to the constructor will be executed and then the object will wait for a window with a matching command to be spawned (see PWMC::getComm() and PWMC::search())

#### protected void startup(string $comm, string $cmd){
This is the method that actually runs the command defined in the constructor. It is isolated from the constructor because we don't want to run it if another object is already waiting for a window with this command. In that case this method will be added to said objects `onReady()`

This is the method you want to overwrite if you're extending `Window` and wants something to run on startup.

#### public void onReady(callable $callback [, array $args])
Defines a callback to be called once the object states changes to ready (or immediately if it is already ready)

Please note that all methods on this class that requires the object to be ready automatically calls `onReady()` and as such you don't need to wrap any call to a method on this class in a callback to this method.

`$args` will be passed to the callback as arguments

#### public void doAfterReady(callable $callback, int $time [, array $args])
This is a shortcut for calling `PWMC::doin()` inside a `Window::onReady()`

Or in other words, it executes `$callback` `$time` milliseconds after the object's state changes to ready.

#### protected void exec(string $cmd)
If you need to execute anything on the associated window in an extending class this is the way to go.

Every call to this method goes through `Window::onReady()` so you don't need to worry about that, furthermore writing `[wid]` in the input string will be replaced with the associated windows ID.

Other than that calling this method is akin to calling `shell_exec($cmd)`

#### public Window move(int $x, int $y [, int $monitor = -1 [, int $desktop = -1]])
Moves the window to the coordinates given in `$x` and `$y`

If `$monitor` is given and is greater than -1 the placement will be relative to said monitor (See `PWMC::getMonitors()` for more info on this argument)

If `$desktop` is given and is greater than -1 the window will also be moved to that workspace.

This method returns `$this` to enable chaining.

#### public Window resize($width, $height)
Resizes the window to `$width` and `$height`

This method returns `$this` to enable chaining.

#### public Window focus()
Brings the window to front and gives it focus

This method returns `$this` to enable chaining.

#### public Window maximize([int $monitor = -1 [, int $desktop = -1]])
Maximizes window

See `Window::move()` for info on how to use `$monitor` and `$desktop`

This method returns `$this` to enable chaining.

#### public Window fullScreen([int $monitor = -1 [, int $desktop = -1]])
Puts the window in full screen.

See `Window::move()` for info on how to use `$monitor` and `$desktop`

This method returns `$this` to enable chaining.

#### public Window normalize()
Removes maximized and full screen from the window.

This method returns `$this` to enable chaining.

#### public void close
Closes the window.

#### public Window press(string $key)
Sends keystroke to window

Uses xdotool's `key` function. See xdotool's manual for information on what keys can be sent.

This method returns `$this` to enable chaining.

#### public Window type(string $str)
Types string to window with a delay of 12 milliseconds between each stroke.

Uses xdotool's `type` function.

This method returns `$this` to enable chaining.

### Class: Firefox extends Window
Custom class for working with firefox

#### public \_\_construct(string $url = '')
Unlike its parent a `new Firefox('str')` will always execute a command to open firefox and instead uses its parameter for the URL to open.

#### public Firefox newTab(string $url = '')
Opens a new tab with the `$url`

Internally uses xdotool to take focus before executing `'firefox --new-tab '.$url`

This method returns `$this` to enable chaining.

#### public Firefox switchTab(int $tab)
Switches to tab number.

Internally uses xdotool to press alt + `$tab` on the window.

This method returns `$this` to enable chaining.

#### public Firefox closeTab([int $tab])
Closes the numbered tab if number is given. Else closes current tab.

Internally calls `Firefox::switchTab($tab)` as needed. Then uses xdotool to press ctrl + w

This method returns `$this` to enable chaining.

## To-do's
Future implementation plans

### Timeout on windows
Move timeout to individual windows rather than having an overall timeout. This allows for the following new methods.

#### Window::onTimeout()
Like `Window::onReady()` but on timeout instead

#### Window::setTimeout()
Sets how long to wait for window before timeouting it.

### X server direct
Connect directly to the X server rather than keep going through wmctrl and xdotool

### Wait on blocks
Allows the script to wait for a blocked window to actually appear before exiting
