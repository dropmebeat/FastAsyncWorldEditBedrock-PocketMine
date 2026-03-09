<?php

declare(strict_types=1);

namespace icrafts\fastasyncworldeditbedrock;

use pocketmine\block\Air;
use pocketmine\block\Block;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\server\CommandEvent;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat;
use pocketmine\world\World;
use function abs;
use function array_key_exists;
use function array_pop;
use function array_shift;
use function count;
use function floor;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function ltrim;
use function max;
use function min;
use function preg_split;
use function round;
use function sqrt;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function trim;

final class FastAsyncWorldEditBedrock extends PluginBase implements Listener
{
    /** @var array<string, array{world: string, pos1?: array{x:int,y:int,z:int}, pos2?: array{x:int,y:int,z:int}}> */
    private array $selections = [];

    /** @var array<string, list<array{world: string, blocks: list<array{x:int,y:int,z:int,block:Block}>}>> */
    private array $undoStack = [];

    /** @var array<string, array{world:string,origin:array{x:int,y:int,z:int},blocks:list<array{dx:int,dy:int,dz:int,block:Block}>}> */
    private array $clipboards = [];

    /** @var list<array<string, mixed>> */
    private array $queue = [];

    /** @var array<string, mixed>|null */
    private ?array $activeJob = null;

    private int $blocksPerTick = 2500;
    private int $maxChangedBlocks = 75000;
    private int $maxUndoActions = 5;

    public function onEnable(): void
    {
        $this->saveDefaultConfig();
        $this->blocksPerTick = max(
            100,
            (int) $this->getConfig()->get("blocks-per-tick", 2500),
        );
        $this->maxChangedBlocks = max(
            1000,
            (int) $this->getConfig()->get("max-changed-blocks", 75000),
        );
        $this->maxUndoActions = max(
            1,
            (int) $this->getConfig()->get("max-undo-actions", 5),
        );

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(function (): void {
                $this->tickQueue();
            }),
            1,
        );
    }

    public function onCommandEvent(CommandEvent $event): void
    {
        $raw = trim($event->getCommand());
        $startsDouble = str_starts_with($raw, "//");
        $startsSingle = str_starts_with($raw, "/");
        if (!$startsDouble && !$startsSingle) {
            return;
        }

        $sender = $event->getSender();
        if (!$sender instanceof Player) {
            $event->cancel();
            return;
        }
        $line = ltrim($raw, "/");
        $line = trim($line);
        if ($line === "") {
            $event->cancel();
            $this->sendHelp($sender);
            return;
        }
        $parts = preg_split("/\s+/", $line) ?: [];
        $sub = strtolower((string) array_shift($parts));
        $known = in_array(
            $sub,
            [
                "wand",
                "pos1",
                "pos2",
                "set",
                "replace",
                "copy",
                "paste",
                "stack",
                "sphere",
                "undo",
            ],
            true,
        );
        if (!$known && !$startsDouble) {
            return;
        }

        $isWandCommand = in_array($sub, ["wand", "pos1", "pos2"], true);
        if ($isWandCommand) {
            if (!$sender->hasPermission("fawe.wand")) {
                $event->cancel();
                $this->msg($sender, "no_permission");
                return;
            }
        } else {
            if (!$sender->hasPermission("fawe.edit")) {
                $event->cancel();
                $this->msg($sender, "no_permission");
                return;
            }
        }
        $event->cancel();

        switch ($sub) {
            case "wand":
                $this->handleWand($sender);
                break;
            case "pos1":
                $this->handlePosCommand($sender, 1, $parts);
                break;
            case "pos2":
                $this->handlePosCommand($sender, 2, $parts);
                break;
            case "set":
                $this->handleSet($sender, $parts);
                break;
            case "replace":
                $this->handleReplace($sender, $parts);
                break;
            case "copy":
                $this->handleCopy($sender);
                break;
            case "paste":
                $this->handlePaste($sender);
                break;
            case "stack":
                $this->handleStack($sender, $parts);
                break;
            case "sphere":
                $this->handleSphere($sender, $parts);
                break;
            case "undo":
                $this->handleUndo($sender, $parts);
                break;
            default:
                $this->sendHelp($sender);
                break;
        }
    }

    public function onInteract(PlayerInteractEvent $event): void
    {
        $player = $event->getPlayer();
        if (!$player->hasPermission("fawe.wand")) {
            return;
        }
        if (
            $event->getItem()->getTypeId() !==
            VanillaItems::WOODEN_AXE()->getTypeId()
        ) {
            return;
        }

        $pos = $event->getBlock()->getPosition();
        $point =
            $event->getAction() === PlayerInteractEvent::LEFT_CLICK_BLOCK
                ? 1
                : 2;
        $this->setPoint(
            $player,
            $point,
            $pos->getFloorX(),
            $pos->getFloorY(),
            $pos->getFloorZ(),
            $pos->getWorld()->getFolderName(),
        );
        $event->cancel();
    }

    private function handleWand(Player $player): void
    {
        if (!$player->hasPermission("fawe.wand")) {
            $this->msg($player, "no_permission");
            return;
        }
        $leftovers = $player
            ->getInventory()
            ->addItem(VanillaItems::WOODEN_AXE());
        foreach ($leftovers as $item) {
            $player->getWorld()->dropItem($player->getPosition(), $item);
        }
        $this->msg($player, "wand_given");
    }

    /**
     * @param list<string> $args
     */
    private function handlePosCommand(
        Player $player,
        int $point,
        array $args,
    ): void {
        if (count($args) >= 3) {
            $x = (int) $args[0];
            $y = (int) $args[1];
            $z = (int) $args[2];
        } else {
            $p = $player->getPosition();
            $x = $p->getFloorX();
            $y = $p->getFloorY();
            $z = $p->getFloorZ();
        }
        $this->setPoint(
            $player,
            $point,
            $x,
            $y,
            $z,
            $player->getWorld()->getFolderName(),
        );
    }

    /**
     * @param list<string> $args
     */
    private function handleSet(Player $player, array $args): void
    {
        if (!$player->hasPermission("fawe.edit")) {
            $this->msg($player, "no_permission");
            return;
        }
        if (count($args) < 1) {
            $this->sendHelp($player);
            return;
        }

        $target = $this->parseBlock((string) $args[0]);
        if (!$target instanceof Block) {
            $this->msg($player, "invalid_block", [
                "{block}" => (string) $args[0],
            ]);
            return;
        }

        $bounds = $this->getSelectionBounds($player);
        if ($bounds === null) {
            $this->msg($player, "need_selection");
            return;
        }
        if ($bounds["size"] > $this->maxChangedBlocks) {
            $this->msg($player, "selection_too_big", [
                "{size}" => (string) $bounds["size"],
                "{limit}" => (string) $this->maxChangedBlocks,
            ]);
            return;
        }

        $this->queue[] = [
            "mode" => "set",
            "player" => strtolower($player->getName()),
            "notify" => $player->getName(),
            "world" => $bounds["world"],
            "target" => $target,
            "from" => null,
            "minX" => $bounds["minX"],
            "minY" => $bounds["minY"],
            "minZ" => $bounds["minZ"],
            "maxX" => $bounds["maxX"],
            "maxY" => $bounds["maxY"],
            "maxZ" => $bounds["maxZ"],
            "x" => $bounds["minX"],
            "y" => $bounds["minY"],
            "z" => $bounds["minZ"],
            "size" => $bounds["size"],
            "scanned" => 0,
            "changed" => 0,
            "undo" => [],
        ];
        $this->msg($player, "queued", [
            "{type}" => "//set",
            "{size}" => (string) $bounds["size"],
        ]);
        if ($this->activeJob !== null) {
            $this->msg($player, "running");
        }
    }

    /**
     * @param list<string> $args
     */
    private function handleReplace(Player $player, array $args): void
    {
        if (!$player->hasPermission("fawe.edit")) {
            $this->msg($player, "no_permission");
            return;
        }
        if (count($args) < 2) {
            $this->sendHelp($player);
            return;
        }

        $from = $this->parseBlock((string) $args[0]);
        $target = $this->parseBlock((string) $args[1]);
        if (!$from instanceof Block) {
            $this->msg($player, "invalid_block", [
                "{block}" => (string) $args[0],
            ]);
            return;
        }
        if (!$target instanceof Block) {
            $this->msg($player, "invalid_block", [
                "{block}" => (string) $args[1],
            ]);
            return;
        }

        $bounds = $this->getSelectionBounds($player);
        if ($bounds === null) {
            $this->msg($player, "need_selection");
            return;
        }
        if ($bounds["size"] > $this->maxChangedBlocks) {
            $this->msg($player, "selection_too_big", [
                "{size}" => (string) $bounds["size"],
                "{limit}" => (string) $this->maxChangedBlocks,
            ]);
            return;
        }

        $this->queue[] = [
            "mode" => "replace",
            "player" => strtolower($player->getName()),
            "notify" => $player->getName(),
            "world" => $bounds["world"],
            "target" => $target,
            "from" => $from,
            "minX" => $bounds["minX"],
            "minY" => $bounds["minY"],
            "minZ" => $bounds["minZ"],
            "maxX" => $bounds["maxX"],
            "maxY" => $bounds["maxY"],
            "maxZ" => $bounds["maxZ"],
            "x" => $bounds["minX"],
            "y" => $bounds["minY"],
            "z" => $bounds["minZ"],
            "size" => $bounds["size"],
            "scanned" => 0,
            "changed" => 0,
            "undo" => [],
        ];
        $this->msg($player, "queued", [
            "{type}" => "//replace",
            "{size}" => (string) $bounds["size"],
        ]);
        if ($this->activeJob !== null) {
            $this->msg($player, "running");
        }
    }

    private function handleCopy(Player $player): void
    {
        $bounds = $this->getSelectionBounds($player);
        if ($bounds === null) {
            $this->msg($player, "need_selection");
            return;
        }
        if ($bounds["size"] > $this->maxChangedBlocks) {
            $this->msg($player, "selection_too_big", [
                "{size}" => (string) $bounds["size"],
                "{limit}" => (string) $this->maxChangedBlocks,
            ]);
            return;
        }

        $world = $this->getServer()
            ->getWorldManager()
            ->getWorldByName($bounds["world"]);
        if (!$world instanceof World) {
            $player->sendMessage(
                TextFormat::colorize("&cWorld not loaded: " . $bounds["world"]),
            );
            return;
        }

        $blocks = [];
        for ($y = $bounds["minY"]; $y <= $bounds["maxY"]; $y++) {
            for ($z = $bounds["minZ"]; $z <= $bounds["maxZ"]; $z++) {
                for ($x = $bounds["minX"]; $x <= $bounds["maxX"]; $x++) {
                    $current = $world->getBlockAt($x, $y, $z, true, false);
                    $blocks[] = [
                        "dx" => $x - $bounds["minX"],
                        "dy" => $y - $bounds["minY"],
                        "dz" => $z - $bounds["minZ"],
                        "block" => clone $current,
                    ];
                }
            }
        }

        $name = strtolower($player->getName());
        $this->clipboards[$name] = [
            "world" => $bounds["world"],
            "origin" => [
                "x" => $bounds["minX"],
                "y" => $bounds["minY"],
                "z" => $bounds["minZ"],
            ],
            "blocks" => $blocks,
        ];
        $this->msg($player, "copied", ["{size}" => (string) count($blocks)]);
    }

    private function handlePaste(Player $player): void
    {
        $name = strtolower($player->getName());
        $clipboard = $this->clipboards[$name] ?? null;
        if (!is_array($clipboard)) {
            $this->msg($player, "no_clipboard");
            return;
        }

        $origin = $clipboard["origin"] ?? null;
        $blocks = $clipboard["blocks"] ?? null;
        if (!is_array($origin) || !is_array($blocks)) {
            $this->msg($player, "no_clipboard");
            return;
        }

        $base = $player->getPosition();
        $baseX = $base->getFloorX();
        $baseY = $base->getFloorY();
        $baseZ = $base->getFloorZ();
        $ops = [];
        foreach ($blocks as $entry) {
            if (
                !is_array($entry) ||
                !($entry["block"] ?? null) instanceof Block
            ) {
                continue;
            }
            $ops[] = [
                "x" => $baseX + (int) ($entry["dx"] ?? 0),
                "y" => $baseY + (int) ($entry["dy"] ?? 0),
                "z" => $baseZ + (int) ($entry["dz"] ?? 0),
                "block" => clone $entry["block"],
            ];
        }
        $this->queueOps($player, "//paste", $ops);
    }

    /**
     * @param list<string> $args
     */
    private function handleStack(Player $player, array $args): void
    {
        if (count($args) < 1) {
            $this->sendHelp($player);
            return;
        }
        $countTimes = max(1, (int) $args[0]);
        $dir = $this->resolveDirection($player, (string) ($args[1] ?? ""));
        $bounds = $this->getSelectionBounds($player);
        if ($bounds === null) {
            $this->msg($player, "need_selection");
            return;
        }

        $world = $this->getServer()
            ->getWorldManager()
            ->getWorldByName($bounds["world"]);
        if (!$world instanceof World) {
            $player->sendMessage(
                TextFormat::colorize("&cWorld not loaded: " . $bounds["world"]),
            );
            return;
        }

        $source = [];
        for ($y = $bounds["minY"]; $y <= $bounds["maxY"]; $y++) {
            for ($z = $bounds["minZ"]; $z <= $bounds["maxZ"]; $z++) {
                for ($x = $bounds["minX"]; $x <= $bounds["maxX"]; $x++) {
                    $source[] = [
                        "x" => $x,
                        "y" => $y,
                        "z" => $z,
                        "block" => clone $world->getBlockAt(
                            $x,
                            $y,
                            $z,
                            true,
                            false,
                        ),
                    ];
                }
            }
        }

        $sizeX = $bounds["maxX"] - $bounds["minX"] + 1;
        $sizeY = $bounds["maxY"] - $bounds["minY"] + 1;
        $sizeZ = $bounds["maxZ"] - $bounds["minZ"] + 1;
        $offsetX = (int) $dir["x"] * $sizeX;
        $offsetY = (int) $dir["y"] * $sizeY;
        $offsetZ = (int) $dir["z"] * $sizeZ;

        $total = count($source) * $countTimes;
        if ($total > $this->maxChangedBlocks) {
            $this->msg($player, "selection_too_big", [
                "{size}" => (string) $total,
                "{limit}" => (string) $this->maxChangedBlocks,
            ]);
            return;
        }

        $ops = [];
        for ($i = 1; $i <= $countTimes; $i++) {
            foreach ($source as $entry) {
                $ops[] = [
                    "x" => (int) $entry["x"] + $offsetX * $i,
                    "y" => (int) $entry["y"] + $offsetY * $i,
                    "z" => (int) $entry["z"] + $offsetZ * $i,
                    "block" => clone $entry["block"],
                ];
            }
        }
        $this->queueOps($player, "//stack", $ops);
    }

    /**
     * @param list<string> $args
     */
    private function handleSphere(Player $player, array $args): void
    {
        if (count($args) < 2) {
            $this->sendHelp($player);
            return;
        }
        $target = $this->parseBlock((string) $args[0]);
        if (!$target instanceof Block) {
            $this->msg($player, "invalid_block", [
                "{block}" => (string) $args[0],
            ]);
            return;
        }
        $radius = max(1, (int) $args[1]);
        $hollow =
            isset($args[2]) &&
            in_array(
                strtolower((string) $args[2]),
                ["hollow", "true", "1", "yes"],
                true,
            );

        $center = $player->getPosition();
        $cx = $center->getFloorX();
        $cy = $center->getFloorY();
        $cz = $center->getFloorZ();
        $r2 = $radius * $radius;
        $inner2 = ($radius - 1) * ($radius - 1);

        $ops = [];
        for ($x = -$radius; $x <= $radius; $x++) {
            for ($y = -$radius; $y <= $radius; $y++) {
                for ($z = -$radius; $z <= $radius; $z++) {
                    $dist2 = $x * $x + $y * $y + $z * $z;
                    if ($dist2 > $r2) {
                        continue;
                    }
                    if ($hollow && $dist2 < $inner2) {
                        continue;
                    }
                    $ops[] = [
                        "x" => $cx + $x,
                        "y" => $cy + $y,
                        "z" => $cz + $z,
                        "block" => clone $target,
                    ];
                }
            }
        }
        $this->queueOps($player, "//sphere", $ops);
    }

    /**
     * @param list<string> $args
     */
    private function handleUndo(Player $player, array $args): void
    {
        if (!$player->hasPermission("fawe.edit")) {
            $this->msg($player, "no_permission");
            return;
        }

        $countToUndo = isset($args[0]) ? max(1, (int) $args[0]) : 1;
        $name = strtolower($player->getName());
        $stack = $this->undoStack[$name] ?? [];
        if ($stack === []) {
            $this->msg($player, "no_undo");
            return;
        }

        $merged = [];
        $world = null;
        for ($i = 0; $i < $countToUndo; $i++) {
            $action = array_pop($stack);
            if (!is_array($action)) {
                break;
            }
            if ($world === null) {
                $world = (string) ($action["world"] ?? "");
            }
            $blocks = $action["blocks"] ?? [];
            if (!is_array($blocks)) {
                continue;
            }
            foreach ($blocks as $entry) {
                if (is_array($entry)) {
                    $merged[] = $entry;
                }
            }
        }
        $this->undoStack[$name] = $stack;

        if ($merged === [] || $world === null || $world === "") {
            $this->msg($player, "no_undo");
            return;
        }

        $this->queue[] = [
            "mode" => "undo",
            "player" => $name,
            "notify" => $player->getName(),
            "world" => $world,
            "undoList" => $merged,
            "undoIndex" => 0,
            "size" => count($merged),
            "scanned" => 0,
            "changed" => 0,
        ];
        if ($this->activeJob !== null) {
            $this->msg($player, "running");
        }
    }

    /**
     * @param list<array{x:int,y:int,z:int,block:Block}> $ops
     */
    private function queueOps(Player $player, string $type, array $ops): void
    {
        if (!$player->hasPermission("fawe.edit")) {
            $this->msg($player, "no_permission");
            return;
        }
        if ($ops === []) {
            return;
        }
        if (count($ops) > $this->maxChangedBlocks) {
            $this->msg($player, "selection_too_big", [
                "{size}" => (string) count($ops),
                "{limit}" => (string) $this->maxChangedBlocks,
            ]);
            return;
        }
        $this->queue[] = [
            "mode" => "ops",
            "opType" => $type,
            "player" => strtolower($player->getName()),
            "notify" => $player->getName(),
            "world" => $player->getWorld()->getFolderName(),
            "ops" => $ops,
            "opIndex" => 0,
            "size" => count($ops),
            "scanned" => 0,
            "changed" => 0,
            "undo" => [],
        ];
        $this->msg($player, "queued", [
            "{type}" => $type,
            "{size}" => (string) count($ops),
        ]);
        if ($this->activeJob !== null) {
            $this->msg($player, "running");
        }
    }

    private function tickQueue(): void
    {
        if ($this->activeJob === null) {
            $next = array_shift($this->queue);
            if (!is_array($next)) {
                return;
            }
            $this->activeJob = $next;
        }

        $job = $this->activeJob;
        if (!is_array($job)) {
            $this->activeJob = null;
            return;
        }

        $world = $this->getServer()
            ->getWorldManager()
            ->getWorldByName((string) ($job["world"] ?? ""));
        if (!$world instanceof World) {
            $this->finishJob(
                $job,
                "World not loaded: " . (string) ($job["world"] ?? "unknown"),
            );
            return;
        }

        $mode = (string) ($job["mode"] ?? "");
        if ($mode === "undo") {
            $done = $this->processUndoJob($job, $world);
        } elseif ($mode === "ops") {
            $done = $this->processOpsJob($job, $world);
        } else {
            $done = $this->processCuboidJob($job, $world);
        }

        if ($done) {
            $this->finishJob($job, null);
            return;
        }
        $this->activeJob = $job;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function processUndoJob(array &$job, World $world): bool
    {
        $undoList = $job["undoList"] ?? [];
        if (!is_array($undoList)) {
            return true;
        }

        $index = (int) ($job["undoIndex"] ?? 0);
        $limit = min(count($undoList), $index + $this->blocksPerTick);
        for ($i = $index; $i < $limit; $i++) {
            $entry = $undoList[$i] ?? null;
            if (
                !is_array($entry) ||
                !isset($entry["x"], $entry["y"], $entry["z"], $entry["block"])
            ) {
                continue;
            }
            if (!$entry["block"] instanceof Block) {
                continue;
            }
            $x = (int) $entry["x"];
            $y = (int) $entry["y"];
            $z = (int) $entry["z"];
            if (!$world->isInWorld($x, $y, $z)) {
                continue;
            }
            $world->setBlockAt($x, $y, $z, clone $entry["block"], false);
            $job["changed"] = (int) $job["changed"] + 1;
            $job["scanned"] = (int) $job["scanned"] + 1;
        }

        $job["undoIndex"] = $limit;
        return $limit >= count($undoList);
    }

    /**
     * @param array<string, mixed> $job
     */
    private function processOpsJob(array &$job, World $world): bool
    {
        $ops = $job["ops"] ?? [];
        if (!is_array($ops)) {
            return true;
        }
        $index = (int) ($job["opIndex"] ?? 0);
        $limit = min(count($ops), $index + $this->blocksPerTick);

        for ($i = $index; $i < $limit; $i++) {
            $op = $ops[$i] ?? null;
            if (
                !is_array($op) ||
                !isset($op["x"], $op["y"], $op["z"], $op["block"])
            ) {
                continue;
            }
            if (!$op["block"] instanceof Block) {
                continue;
            }
            $x = (int) $op["x"];
            $y = (int) $op["y"];
            $z = (int) $op["z"];
            if (!$world->isInWorld($x, $y, $z)) {
                continue;
            }

            $job["scanned"] = (int) $job["scanned"] + 1;
            $current = $world->getBlockAt($x, $y, $z, true, false);
            if ($current->getStateId() === $op["block"]->getStateId()) {
                continue;
            }

            $job["undo"][] = [
                "x" => $x,
                "y" => $y,
                "z" => $z,
                "block" => clone $current,
            ];
            $world->setBlockAt($x, $y, $z, clone $op["block"], false);
            $job["changed"] = (int) $job["changed"] + 1;
        }

        $job["opIndex"] = $limit;
        return $limit >= count($ops);
    }

    /**
     * @param array<string, mixed> $job
     */
    private function processCuboidJob(array &$job, World $world): bool
    {
        $steps = 0;
        $target = $job["target"] ?? null;
        if (!$target instanceof Block) {
            return true;
        }
        $from = $job["from"] ?? null;
        if ($from !== null && !$from instanceof Block) {
            return true;
        }

        while ($steps < $this->blocksPerTick) {
            $x = (int) ($job["x"] ?? 0);
            $y = (int) ($job["y"] ?? 0);
            $z = (int) ($job["z"] ?? 0);
            $maxX = (int) ($job["maxX"] ?? 0);
            $maxY = (int) ($job["maxY"] ?? 0);
            $maxZ = (int) ($job["maxZ"] ?? 0);
            if ($y > $maxY) {
                return true;
            }

            $job["scanned"] = (int) $job["scanned"] + 1;
            $current = $world->getBlockAt($x, $y, $z, true, false);
            $shouldChange = false;
            if ((string) $job["mode"] === "set") {
                $shouldChange =
                    $current->getStateId() !== $target->getStateId();
            } elseif (
                (string) $job["mode"] === "replace" &&
                $from instanceof Block
            ) {
                $shouldChange =
                    $current->getStateId() === $from->getStateId() &&
                    $current->getStateId() !== $target->getStateId();
            }

            if ($shouldChange) {
                $job["undo"][] = [
                    "x" => $x,
                    "y" => $y,
                    "z" => $z,
                    "block" => clone $current,
                ];
                $world->setBlockAt($x, $y, $z, clone $target, false);
                $job["changed"] = (int) $job["changed"] + 1;
            }

            $steps++;
            if ($x < $maxX) {
                $job["x"] = $x + 1;
            } elseif ($z < $maxZ) {
                $job["x"] = (int) $job["minX"];
                $job["z"] = $z + 1;
            } else {
                $job["x"] = (int) $job["minX"];
                $job["z"] = (int) $job["minZ"];
                $job["y"] = $y + 1;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function finishJob(array $job, ?string $error): void
    {
        $this->activeJob = null;
        $notifyName = (string) ($job["notify"] ?? "");
        $player =
            $notifyName !== ""
                ? $this->getServer()->getPlayerExact($notifyName)
                : null;
        if ($error !== null) {
            if ($player instanceof Player) {
                $player->sendMessage(TextFormat::colorize("&c" . $error));
            }
            return;
        }

        $mode = (string) ($job["mode"] ?? "");
        if ($mode === "undo") {
            if ($player instanceof Player) {
                $this->msg($player, "undo_done", [
                    "{count}" => (string) ((int) ($job["changed"] ?? 0)),
                ]);
            }
            return;
        }

        $undoList = $job["undo"] ?? [];
        if (is_array($undoList) && $undoList !== []) {
            $name = (string) ($job["player"] ?? "");
            $stack = $this->undoStack[$name] ?? [];
            $stack[] = [
                "world" => (string) ($job["world"] ?? ""),
                "blocks" => $undoList,
            ];
            while (count($stack) > $this->maxUndoActions) {
                array_shift($stack);
            }
            $this->undoStack[$name] = $stack;
        }

        if ($player instanceof Player) {
            $type =
                $mode === "ops"
                    ? (string) ($job["opType"] ?? "//edit")
                    : "//" . $mode;
            $this->msg($player, "complete", [
                "{type}" => $type,
                "{changed}" => (string) ((int) ($job["changed"] ?? 0)),
                "{scanned}" => (string) ((int) ($job["scanned"] ?? 0)),
            ]);
        }
    }

    private function parseBlock(string $raw): ?Block
    {
        $id = strtolower(trim($raw));
        if ($id === "") {
            return null;
        }
        $parser = StringToItemParser::getInstance();
        $item = $parser->parse($id);
        if ($item === null && !str_contains($id, ":")) {
            $item = $parser->parse("minecraft:" . $id);
        }
        if ($item === null) {
            return null;
        }
        if (
            !$item->canBePlaced() &&
            !in_array($id, ["air", "minecraft:air"], true)
        ) {
            return null;
        }
        $block = $item->getBlock();
        if (
            $block instanceof Air &&
            !in_array($id, ["air", "minecraft:air"], true)
        ) {
            return null;
        }
        return $block;
    }

    /**
     * @return array{x:int,y:int,z:int}
     */
    private function resolveDirection(Player $player, string $raw): array
    {
        $dir = strtolower(trim($raw));
        if ($dir !== "") {
            return match ($dir) {
                "north", "n" => ["x" => 0, "y" => 0, "z" => -1],
                "south", "s" => ["x" => 0, "y" => 0, "z" => 1],
                "west", "w", "left" => ["x" => -1, "y" => 0, "z" => 0],
                "east", "e", "right" => ["x" => 1, "y" => 0, "z" => 0],
                "up", "u" => ["x" => 0, "y" => 1, "z" => 0],
                "down", "d" => ["x" => 0, "y" => -1, "z" => 0],
                default => ["x" => 1, "y" => 0, "z" => 0],
            };
        }

        $v = $player->getDirectionVector();
        $ax = abs($v->x);
        $az = abs($v->z);
        if ($ax >= $az) {
            return ["x" => $v->x >= 0 ? 1 : -1, "y" => 0, "z" => 0];
        }
        return ["x" => 0, "y" => 0, "z" => $v->z >= 0 ? 1 : -1];
    }

    private function setPoint(
        Player $player,
        int $point,
        int $x,
        int $y,
        int $z,
        string $world,
    ): void {
        $name = strtolower($player->getName());
        if (!isset($this->selections[$name])) {
            $this->selections[$name] = ["world" => $world];
        }
        if ((string) $this->selections[$name]["world"] !== $world) {
            $this->selections[$name] = ["world" => $world];
        }
        $this->selections[$name]["pos" . $point] = [
            "x" => $x,
            "y" => $y,
            "z" => $z,
        ];
        $this->msg($player, "pos_set", [
            "{point}" => (string) $point,
            "{x}" => (string) $x,
            "{y}" => (string) $y,
            "{z}" => (string) $z,
            "{world}" => $world,
        ]);
    }

    /**
     * @return array{world:string,minX:int,minY:int,minZ:int,maxX:int,maxY:int,maxZ:int,size:int}|null
     */
    private function getSelectionBounds(Player $player): ?array
    {
        $name = strtolower($player->getName());
        $sel = $this->selections[$name] ?? null;
        if (!is_array($sel)) {
            return null;
        }
        $p1 = $sel["pos1"] ?? null;
        $p2 = $sel["pos2"] ?? null;
        $world = $sel["world"] ?? null;
        if (!is_array($p1) || !is_array($p2) || !is_string($world)) {
            return null;
        }
        foreach (["x", "y", "z"] as $axis) {
            if (
                !array_key_exists($axis, $p1) ||
                !array_key_exists($axis, $p2)
            ) {
                return null;
            }
            if (!is_int($p1[$axis]) || !is_int($p2[$axis])) {
                return null;
            }
        }

        $minX = min($p1["x"], $p2["x"]);
        $minY = min($p1["y"], $p2["y"]);
        $minZ = min($p1["z"], $p2["z"]);
        $maxX = max($p1["x"], $p2["x"]);
        $maxY = max($p1["y"], $p2["y"]);
        $maxZ = max($p1["z"], $p2["z"]);
        $size = ($maxX - $minX + 1) * ($maxY - $minY + 1) * ($maxZ - $minZ + 1);

        return [
            "world" => $world,
            "minX" => $minX,
            "minY" => $minY,
            "minZ" => $minZ,
            "maxX" => $maxX,
            "maxY" => $maxY,
            "maxZ" => $maxZ,
            "size" => $size,
        ];
    }

    private function sendHelp(Player $player): void
    {
        $lines = $this->getConfig()->getNested("messages.help", []);
        if (!is_array($lines)) {
            return;
        }
        foreach ($lines as $line) {
            if (is_string($line)) {
                $player->sendMessage(TextFormat::colorize($line));
            }
        }
    }

    /**
     * @param array<string, string> $replacements
     */
    private function msg(
        Player $player,
        string $key,
        array $replacements = [],
    ): void {
        $prefix = (string) $this->getConfig()->getNested("messages.prefix", "");
        $text = (string) $this->getConfig()->getNested(
            "messages." . $key,
            $key,
        );
        foreach ($replacements as $from => $to) {
            $text = str_replace($from, $to, $text);
        }
        $player->sendMessage(TextFormat::colorize($prefix . $text));
    }
}
