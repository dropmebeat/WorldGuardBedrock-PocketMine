<?php

declare(strict_types=1);

namespace icrafts\worldguardbedrock;

use pocketmine\block\Block;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockBurnEvent;
use pocketmine\event\block\BlockFormEvent;
use pocketmine\event\block\BlockMeltEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\BlockSpreadEvent;
use pocketmine\event\entity\AreaEffectCloudApplyEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityEffectAddEvent;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\entity\EntitySpawnEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use function array_diff;
use function array_key_exists;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function method_exists;
use function min;
use function str_contains;
use function strtolower;
use function trim;

final class WorldGuardBedrock extends PluginBase implements Listener
{
    private const DATA_REGIONS = "regions";

    /** @var list<string> */
    private const BOOLEAN_FLAGS = [
        "build",
        "interact",
        "pvp",
        "entry",
        "chest-access",
        "access-mods",
        "potion-splash",
        "lightning",
        "lighter",
        "vehicle-destroy",
        "vehicle-place",
        "pistons",
        "ice-form",
        "ice-melt",
        "snow-fall",
        "leaf-decay",
        "ghast-fireball",
        "creeper-explosion",
        "mob-spawning",
        "deny-spawning",
        "mob-damage",
        "teleport",
        "lava-fire",
        "invisible",
        "water-flow",
        "lava-flow",
    ];

    /** @var list<string> */
    private const TEXT_FLAGS = ["greeting", "farewell"];

    /** @var list<string> */
    private const LIST_FLAGS = ["deny-commands"];

    /** @var list<string> */
    private const NUMBER_FLAGS = [
        "feed-max-hunger",
        "feed-min-hunger",
        "heal-amount",
        "heal-delay",
    ];

    private const DEFAULT_FLAGS = [
        "build" => "deny",
        "interact" => "deny",
        "pvp" => "deny",
        "entry" => "allow",
        "greeting" => "",
        "farewell" => "",
        "deny-commands" => [],
        "chest-access" => "allow",
        "access-mods" => "allow",
        "potion-splash" => "allow",
        "lightning" => "allow",
        "lighter" => "allow",
        "vehicle-destroy" => "allow",
        "vehicle-place" => "allow",
        "pistons" => "allow",
        "ice-form" => "allow",
        "ice-melt" => "allow",
        "snow-fall" => "allow",
        "leaf-decay" => "allow",
        "ghast-fireball" => "allow",
        "creeper-explosion" => "allow",
        "mob-spawning" => "allow",
        "deny-spawning" => "allow",
        "mob-damage" => "allow",
        "teleport" => "allow",
        "lava-fire" => "allow",
        "invisible" => "allow",
        "feed-max-hunger" => "",
        "feed-min-hunger" => "",
        "heal-amount" => "",
        "heal-delay" => "",
        "water-flow" => "allow",
        "lava-flow" => "allow",
    ];

    private Config $regionsConfig;

    /** @var array<string, array{world:string,pos1?:array{x:int,y:int,z:int},pos2?:array{x:int,y:int,z:int}}> */
    private array $selections = [];

    /** @var array<string, int> */
    private array $lastHealTick = [];

    public function onEnable(): void
    {
        $this->saveDefaultConfig();
        $this->regionsConfig = new Config(
            $this->getDataFolder() . "regions.yml",
            Config::YAML,
            [self::DATA_REGIONS => []],
        );
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onCommand(
        CommandSender $sender,
        Command $command,
        string $label,
        array $args,
    ): bool {
        if (strtolower($command->getName()) !== "rg") {
            return false;
        }

        if (!$sender instanceof Player) {
            $this->msgRaw($sender, "player_only");
            return true;
        }
        if (!$sender->hasPermission("wg.use")) {
            $this->msg($sender, "no_permission");
            return true;
        }

        $sub = strtolower((string) ($args[0] ?? "help"));
        $rest = $args;
        if ($rest !== []) {
            array_shift($rest);
        }

        return match ($sub) {
            "wand" => $this->cmdWand($sender),
            "pos1" => $this->cmdPos($sender, 1, $rest),
            "pos2" => $this->cmdPos($sender, 2, $rest),
            "claim" => $this->cmdDefine($sender, $rest),
            "define" => $this->cmdDefine($sender, $rest),
            "remove" => $this->cmdRemove($sender, $rest),
            "addmember" => $this->cmdAddMember($sender, $rest),
            "removemember" => $this->cmdRemoveMember($sender, $rest),
            "flag" => $this->cmdFlag($sender, $rest),
            "info" => $this->cmdInfo($sender, $rest),
            "list" => $this->cmdList($sender),
            default => $this->cmdHelp($sender),
        };
    }

    public function onWandSelect(PlayerInteractEvent $event): void
    {
        $player = $event->getPlayer();
        if (
            !$player->hasPermission("wg.use") ||
            !$this->hasManagePermission($player, "wg.region.select")
        ) {
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

    public function onBlockBreak(BlockBreakEvent $event): void
    {
        $player = $event->getPlayer();
        if ($this->isBypass($player)) {
            return;
        }
        if (
            !$this->isAllowed(
                $player,
                $event->getBlock()->getPosition(),
                "build",
            )
        ) {
            $event->cancel();
            $this->msg($player, "build_denied");
        }
    }

    public function onBlockPlace(BlockPlaceEvent $event): void
    {
        $player = $event->getPlayer();
        if ($this->isBypass($player)) {
            return;
        }
        foreach ($event->getTransaction()->getBlocks() as [$x, $y, $z, $_]) {
            $pos = new Position($x, $y, $z, $player->getWorld());
            if (!$this->isAllowed($player, $pos, "build")) {
                $event->cancel();
                $this->msg($player, "build_denied");
                return;
            }
        }
    }

    public function onPlayerInteract(PlayerInteractEvent $event): void
    {
        if ($event->isCancelled()) {
            return;
        }
        $player = $event->getPlayer();
        if ($this->isBypass($player)) {
            return;
        }
        $pos = $event->getBlock()->getPosition();
        if (!$this->isAllowed($player, $pos, "interact")) {
            $event->cancel();
            $this->msg($player, "interact_denied");
            return;
        }

        $block = $event->getBlock();
        if (
            $this->isContainerBlock($block) &&
            !$this->isAllowed($player, $pos, "chest-access")
        ) {
            $event->cancel();
            $this->msg($player, "interact_denied");
            return;
        }

        if (
            $this->isAccessModBlock($block) &&
            !$this->isAllowed($player, $pos, "access-mods")
        ) {
            $event->cancel();
            $this->msg($player, "interact_denied");
            return;
        }

        $item = $event->getItem();
        if (
            $this->isLighterItem($item) &&
            !$this->isAllowed($player, $pos, "lighter")
        ) {
            $event->cancel();
            $this->msg($player, "no_permission");
            return;
        }

        if (
            $this->isVehicleItem($item) &&
            !$this->isAllowed($player, $pos, "vehicle-place")
        ) {
            $event->cancel();
            $this->msg($player, "no_permission");
        }
    }

    public function onMove(PlayerMoveEvent $event): void
    {
        $player = $event->getPlayer();
        if ($this->isBypass($player)) {
            return;
        }
        $to = $event->getTo();
        if (!$to instanceof Position) {
            return;
        }
        if (!$this->isAllowed($player, $to, "entry")) {
            $event->cancel();
            $this->msg($player, "entry_denied");
            return;
        }

        if (
            $event->getFrom()->getFloorX() === $to->getFloorX() &&
            $event->getFrom()->getFloorY() === $to->getFloorY() &&
            $event->getFrom()->getFloorZ() === $to->getFloorZ() &&
            $event->getFrom()->getWorld() === $to->getWorld()
        ) {
            return;
        }
        $this->applyVitalsFlags($player, $to);
    }

    public function onDamage(EntityDamageByEntityEvent $event): void
    {
        $damager = $event->getDamager();
        $victim = $event->getEntity();

        if ($damager instanceof Player && $victim instanceof Player) {
            if ($this->isBypass($damager)) {
                return;
            }
            if (
                !$this->isAllowed($damager, $damager->getPosition(), "pvp") ||
                !$this->isAllowed($damager, $victim->getPosition(), "pvp")
            ) {
                $event->cancel();
                $this->msg($damager, "no_permission");
            }
            return;
        }

        if (
            $victim instanceof Player &&
            !($damager instanceof Player) &&
            $this->isFlagDeniedAt($victim->getPosition(), "mob-damage")
        ) {
            $event->cancel();
            return;
        }

        if (
            $this->isVehicleEntity($victim) &&
            $damager instanceof Player &&
            !$this->isBypass($damager) &&
            !$this->isAllowed(
                $damager,
                $victim->getPosition(),
                "vehicle-destroy",
            )
        ) {
            $event->cancel();
        }
    }

    public function onEntityTeleport(EntityTeleportEvent $event): void
    {
        $entity = $event->getEntity();
        if ($entity instanceof Player && $this->isBypass($entity)) {
            return;
        }
        if ($this->isFlagDeniedAt($event->getTo(), "teleport")) {
            $event->cancel();
            if ($entity instanceof Player) {
                $this->msg($entity, "entry_denied");
            }
        }
    }

    public function onProjectileLaunch(ProjectileLaunchEvent $event): void
    {
        $projectile = $event->getEntity();
        $class = strtolower($projectile::class);
        $pos = $projectile->getPosition();

        if (
            (str_contains($class, "potion") ||
                str_contains($class, "lingering")) &&
            $this->isFlagDeniedAt($pos, "potion-splash")
        ) {
            $event->cancel();
            return;
        }

        if (
            str_contains($class, "fireball") &&
            $this->isFlagDeniedAt($pos, "ghast-fireball")
        ) {
            $event->cancel();
        }
    }

    public function onAreaEffectCloudApply(
        AreaEffectCloudApplyEvent $event,
    ): void {
        if (
            $this->isFlagDeniedAt(
                $event->getEntity()->getPosition(),
                "potion-splash",
            )
        ) {
            $event->cancel();
        }
    }

    public function onEntityEffectAdd(EntityEffectAddEvent $event): void
    {
        $entity = $event->getEntity();
        $effectType = $event->getEffect()->getType();

        if (
            $effectType === VanillaEffects::INVISIBILITY() &&
            $this->isFlagDeniedAt($entity->getPosition(), "invisible")
        ) {
            $event->cancel();
            return;
        }

        if (
            ($effectType === VanillaEffects::POISON() ||
                $effectType === VanillaEffects::INSTANT_DAMAGE()) &&
            $this->isFlagDeniedAt($entity->getPosition(), "potion-splash")
        ) {
            $event->cancel();
        }
    }

    public function onEntityExplode(EntityExplodeEvent $event): void
    {
        $entity = $event->getEntity();
        $class = strtolower($entity::class);
        $pos = $event->getPosition();

        if (
            str_contains($class, "creeper") &&
            $this->isFlagDeniedAt($pos, "creeper-explosion")
        ) {
            $event->cancel();
            return;
        }

        if (
            str_contains($class, "fireball") &&
            $this->isFlagDeniedAt($pos, "ghast-fireball")
        ) {
            $event->cancel();
            return;
        }

        if ($this->isFlagDeniedAt($pos, "lava-fire")) {
            $event->setIgnitions([]);
        }
    }

    public function onEntitySpawn(EntitySpawnEvent $event): void
    {
        $entity = $event->getEntity();
        $class = strtolower($entity::class);
        if (
            str_contains($class, "lightning") &&
            $this->isFlagDeniedAt($entity->getPosition(), "lightning")
        ) {
            $entity->flagForDespawn();
            $entity->close();
            return;
        }

        if ($entity instanceof Player) {
            return;
        }
        if (!$entity instanceof Living) {
            return;
        }
        $pos = $entity->getPosition();

        if (
            $this->isFlagDeniedAt($pos, "mob-spawning") ||
            $this->isFlagDeniedAt($pos, "deny-spawning")
        ) {
            $entity->flagForDespawn();
            $entity->close();
        }
    }

    public function onBlockForm(BlockFormEvent $event): void
    {
        $new = $event->getNewState();
        $pos = $event->getBlock()->getPosition();

        if (
            $this->isSnowBlock($new) &&
            $this->isFlagDeniedAt($pos, "snow-fall")
        ) {
            $event->cancel();
            return;
        }
        if (
            $this->isIceBlock($new) &&
            $this->isFlagDeniedAt($pos, "ice-form")
        ) {
            $event->cancel();
            return;
        }
        if (
            $this->isWaterBlock($new) &&
            $this->isFlagDeniedAt($pos, "water-flow")
        ) {
            $event->cancel();
            return;
        }
        if (
            $this->isLavaBlock($new) &&
            $this->isFlagDeniedAt($pos, "lava-flow")
        ) {
            $event->cancel();
            return;
        }
        if (
            $this->isPistonBlock($event->getCausingBlock()) &&
            $this->isFlagDeniedAt($pos, "pistons")
        ) {
            $event->cancel();
        }
    }

    public function onBlockMelt(BlockMeltEvent $event): void
    {
        if (
            $this->isFlagDeniedAt($event->getBlock()->getPosition(), "ice-melt")
        ) {
            $event->cancel();
        }
    }

    public function onBlockSpread(BlockSpreadEvent $event): void
    {
        $new = $event->getNewState();
        $pos = $event->getBlock()->getPosition();
        if (
            $this->isWaterBlock($new) &&
            $this->isFlagDeniedAt($pos, "water-flow")
        ) {
            $event->cancel();
            return;
        }
        if (
            $this->isLavaBlock($new) &&
            $this->isFlagDeniedAt($pos, "lava-flow")
        ) {
            $event->cancel();
            return;
        }
        if (
            $this->isFireBlock($new) &&
            $this->isFlagDeniedAt($pos, "lava-fire")
        ) {
            $event->cancel();
            return;
        }
        if (
            $this->isLeavesBlock($new) &&
            $this->isFlagDeniedAt($pos, "leaf-decay")
        ) {
            $event->cancel();
            return;
        }
        if (
            $this->isPistonBlock($event->getSource()) &&
            $this->isFlagDeniedAt($pos, "pistons")
        ) {
            $event->cancel();
        }
    }

    public function onBlockBurn(BlockBurnEvent $event): void
    {
        if (
            $this->isFlagDeniedAt(
                $event->getBlock()->getPosition(),
                "lava-fire",
            )
        ) {
            $event->cancel();
        }
    }

    public function getRegionAt(Position $position): ?array
    {
        $world = $position->getWorld()->getFolderName();
        $regions = $this->getRegionsByWorld($world);
        $x = $position->getFloorX();
        $y = $position->getFloorY();
        $z = $position->getFloorZ();

        $bestId = null;
        $bestRegion = null;
        $bestVolume = null;
        foreach ($regions as $id => $region) {
            if (!$this->isPointInRegion($x, $y, $z, $region)) {
                continue;
            }
            $volume = $this->getRegionVolume($region);
            if ($bestVolume === null || $volume < $bestVolume) {
                $bestVolume = $volume;
                $bestId = $id;
                $bestRegion = $region;
            }
        }

        if ($bestId === null || !is_array($bestRegion)) {
            return null;
        }
        return ["id" => $bestId, "region" => $bestRegion, "world" => $world];
    }

    public function getFlagForPosition(Position $position, string $flag): mixed
    {
        $at = $this->getRegionAt($position);
        if (!is_array($at)) {
            return null;
        }
        $region = $at["region"] ?? null;
        if (!is_array($region)) {
            return null;
        }
        $flags = $region["flags"] ?? [];
        if (!is_array($flags)) {
            return null;
        }
        return $flags[$flag] ?? null;
    }

    private function cmdHelp(Player $player): bool
    {
        $lines = $this->getConfig()->getNested("messages.help", []);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (is_string($line)) {
                    $player->sendMessage(TextFormat::colorize($line));
                }
            }
        }
        return true;
    }

    private function cmdWand(Player $player): bool
    {
        if (!$this->hasManagePermission($player, "wg.region.wand")) {
            $this->msg($player, "no_permission");
            return true;
        }
        $left = $player->getInventory()->addItem(VanillaItems::WOODEN_AXE());
        foreach ($left as $item) {
            $player->getWorld()->dropItem($player->getPosition(), $item);
        }
        $this->msg($player, "wand_given");
        return true;
    }

    /**
     * @param list<string> $args
     */
    private function cmdPos(Player $player, int $point, array $args): bool
    {
        if (!$this->hasManagePermission($player, "wg.region.select")) {
            $this->msg($player, "no_permission");
            return true;
        }
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
        return true;
    }

    /**
     * @param list<string> $args
     */
    private function cmdDefine(Player $player, array $args): bool
    {
        if (!$this->hasManagePermission($player, "wg.region.claim")) {
            $this->msg($player, "no_permission");
            return true;
        }
        $id = strtolower(trim((string) ($args[0] ?? "")));
        if ($id === "") {
            return $this->cmdHelp($player);
        }
        $bounds = $this->getSelection($player);
        if ($bounds === null) {
            $this->msg($player, "need_selection");
            return true;
        }

        $world = $bounds["world"];
        $regions = $this->getRegionsByWorld($world);
        if (array_key_exists($id, $regions)) {
            $this->msg($player, "region_exists", ["{id}" => $id]);
            return true;
        }

        $regions[$id] = [
            "owner" => strtolower($player->getName()),
            "members" => [],
            "min" => [
                "x" => $bounds["minX"],
                "y" => $bounds["minY"],
                "z" => $bounds["minZ"],
            ],
            "max" => [
                "x" => $bounds["maxX"],
                "y" => $bounds["maxY"],
                "z" => $bounds["maxZ"],
            ],
            "flags" => self::DEFAULT_FLAGS,
        ];
        $this->setRegionsByWorld($world, $regions);
        $this->msg($player, "region_created", ["{id}" => $id]);
        return true;
    }

    /**
     * @param list<string> $args
     */
    private function cmdRemove(Player $player, array $args): bool
    {
        if (!$this->hasManagePermission($player, "wg.region.remove")) {
            $this->msg($player, "no_permission");
            return true;
        }
        $id = strtolower(trim((string) ($args[0] ?? "")));
        if ($id === "") {
            return $this->cmdHelp($player);
        }
        $world = $player->getWorld()->getFolderName();
        $regions = $this->getRegionsByWorld($world);
        $region = $regions[$id] ?? null;
        if (!is_array($region)) {
            $this->msg($player, "region_not_found", ["{id}" => $id]);
            return true;
        }
        if (!$this->canManageRegion($player, $region)) {
            $this->msg($player, "no_permission");
            return true;
        }
        unset($regions[$id]);
        $this->setRegionsByWorld($world, $regions);
        $this->msg($player, "region_removed", ["{id}" => $id]);
        return true;
    }

    /**
     * @param list<string> $args
     */
    private function cmdAddMember(Player $player, array $args): bool
    {
        if (!$this->hasManagePermission($player, "wg.region.member")) {
            $this->msg($player, "no_permission");
            return true;
        }
        $id = strtolower(trim((string) ($args[0] ?? "")));
        $name = strtolower(trim((string) ($args[1] ?? "")));
        if ($id === "" || $name === "") {
            return $this->cmdHelp($player);
        }
        $world = $player->getWorld()->getFolderName();
        $regions = $this->getRegionsByWorld($world);
        $region = $regions[$id] ?? null;
        if (!is_array($region)) {
            $this->msg($player, "region_not_found", ["{id}" => $id]);
            return true;
        }
        if (!$this->canManageRegion($player, $region)) {
            $this->msg($player, "no_permission");
            return true;
        }
        $members = $region["members"] ?? [];
        if (!is_array($members)) {
            $members = [];
        }
        if (!in_array($name, $members, true)) {
            $members[] = $name;
        }
        $region["members"] = $members;
        $regions[$id] = $region;
        $this->setRegionsByWorld($world, $regions);
        $this->msg($player, "member_added", ["{name}" => $name, "{id}" => $id]);
        return true;
    }

    /**
     * @param list<string> $args
     */
    private function cmdRemoveMember(Player $player, array $args): bool
    {
        if (!$this->hasManagePermission($player, "wg.region.member")) {
            $this->msg($player, "no_permission");
            return true;
        }
        $id = strtolower(trim((string) ($args[0] ?? "")));
        $name = strtolower(trim((string) ($args[1] ?? "")));
        if ($id === "" || $name === "") {
            return $this->cmdHelp($player);
        }
        $world = $player->getWorld()->getFolderName();
        $regions = $this->getRegionsByWorld($world);
        $region = $regions[$id] ?? null;
        if (!is_array($region)) {
            $this->msg($player, "region_not_found", ["{id}" => $id]);
            return true;
        }
        if (!$this->canManageRegion($player, $region)) {
            $this->msg($player, "no_permission");
            return true;
        }
        $members = $region["members"] ?? [];
        if (!is_array($members)) {
            $members = [];
        }
        $region["members"] = array_values(array_diff($members, [$name]));
        $regions[$id] = $region;
        $this->setRegionsByWorld($world, $regions);
        $this->msg($player, "member_removed", [
            "{name}" => $name,
            "{id}" => $id,
        ]);
        return true;
    }

    /**
     * @param list<string> $args
     */
    private function cmdFlag(Player $player, array $args): bool
    {
        if (!$this->hasManagePermission($player, "wg.region.flag")) {
            $this->msg($player, "no_permission");
            return true;
        }
        $id = strtolower(trim((string) ($args[0] ?? "")));
        $flag = strtolower(trim((string) ($args[1] ?? "")));
        if ($id === "" || $flag === "") {
            return $this->cmdHelp($player);
        }

        $world = $player->getWorld()->getFolderName();
        $regions = $this->getRegionsByWorld($world);
        $region = $regions[$id] ?? null;
        if (!is_array($region)) {
            $this->msg($player, "region_not_found", ["{id}" => $id]);
            return true;
        }
        if (!$this->canManageRegion($player, $region)) {
            $this->msg($player, "no_permission");
            return true;
        }
        $flags = $region["flags"] ?? [];
        if (!is_array($flags)) {
            $flags = self::DEFAULT_FLAGS;
        }

        $rawValue = trim(implode(" ", array_slice($args, 2)));
        $human = "";
        if (in_array($flag, self::LIST_FLAGS, true)) {
            $cmds = [];
            if ($rawValue !== "") {
                foreach (explode(",", $rawValue) as $part) {
                    $cmd = strtolower(trim($part));
                    if ($cmd !== "") {
                        $cmds[] = $cmd;
                    }
                }
            }
            $flags[$flag] = $cmds;
            $human = implode(", ", $cmds);
        } elseif (in_array($flag, self::BOOLEAN_FLAGS, true)) {
            $v = strtolower($rawValue);
            $value = in_array($v, ["allow", "true", "on", "yes", "1"], true)
                ? "allow"
                : "deny";
            $flags[$flag] = $value;
            $human = $value;
        } elseif (in_array($flag, self::TEXT_FLAGS, true)) {
            $flags[$flag] = $rawValue;
            $human = $rawValue;
        } elseif (in_array($flag, self::NUMBER_FLAGS, true)) {
            $value = is_numeric($rawValue) ? (string) (0 + $rawValue) : "";
            $flags[$flag] = $value;
            $human = $value;
        } else {
            return $this->cmdHelp($player);
        }

        $region["flags"] = $flags;
        $regions[$id] = $region;
        $this->setRegionsByWorld($world, $regions);
        $this->msg($player, "flag_set", [
            "{id}" => $id,
            "{flag}" => $flag,
            "{value}" => $human,
        ]);
        return true;
    }

    /**
     * @param list<string> $args
     */
    private function cmdInfo(Player $player, array $args): bool
    {
        if (!$this->hasManagePermission($player, "wg.region.info")) {
            $this->msg($player, "no_permission");
            return true;
        }
        $world = $player->getWorld()->getFolderName();
        $id = strtolower(trim((string) ($args[0] ?? "")));
        if ($id === "") {
            $at = $this->getRegionAt($player->getPosition());
            if (!is_array($at)) {
                $this->msg($player, "region_not_found", ["{id}" => "current"]);
                return true;
            }
            $id = (string) $at["id"];
        }

        $regions = $this->getRegionsByWorld($world);
        $region = $regions[$id] ?? null;
        if (!is_array($region)) {
            $this->msg($player, "region_not_found", ["{id}" => $id]);
            return true;
        }

        $min = $region["min"] ?? [];
        $max = $region["max"] ?? [];
        $members = $region["members"] ?? [];
        $flags = $region["flags"] ?? [];
        $this->msg($player, "info_header", ["{id}" => $id]);
        $this->msg($player, "info_world", ["{world}" => $world]);
        $this->msg($player, "info_bounds", [
            "{min}" => $this->vecText($min),
            "{max}" => $this->vecText($max),
        ]);
        $this->msg($player, "info_owner", [
            "{owner}" => (string) ($region["owner"] ?? "-"),
        ]);
        $this->msg($player, "info_members", [
            "{members}" => is_array($members) ? implode(", ", $members) : "-",
        ]);
        $this->msg($player, "info_flags", [
            "{flags}" => $this->flagsText($flags),
        ]);
        return true;
    }

    private function cmdList(Player $player): bool
    {
        if (!$this->hasManagePermission($player, "wg.region.list")) {
            $this->msg($player, "no_permission");
            return true;
        }
        $world = $player->getWorld()->getFolderName();
        $regions = $this->getRegionsByWorld($world);
        $this->msg($player, "list_header", ["{world}" => $world]);
        foreach ($regions as $id => $region) {
            if (!is_array($region)) {
                continue;
            }
            $this->msg($player, "list_item", [
                "{id}" => (string) $id,
                "{owner}" => (string) ($region["owner"] ?? "-"),
            ]);
        }
        return true;
    }

    private function isAllowed(
        Player $player,
        Position $position,
        string $flag,
    ): bool {
        $at = $this->getRegionAt($position);
        if (!is_array($at)) {
            return true;
        }
        $region = $at["region"] ?? null;
        if (!is_array($region)) {
            return true;
        }
        if ($this->isMemberOrOwner($player, $region)) {
            return true;
        }
        $flags = $region["flags"] ?? [];
        if (!is_array($flags)) {
            return true;
        }
        $value = strtolower((string) ($flags[$flag] ?? "allow"));
        return $value !== "deny";
    }

    private function isFlagDeniedAt(Position $position, string $flag): bool
    {
        $value = strtolower(
            (string) ($this->getFlagForPosition($position, $flag) ?? "allow"),
        );
        return $value === "deny";
    }

    private function applyVitalsFlags(Player $player, Position $position): void
    {
        $at = $this->getRegionAt($position);
        if (!is_array($at)) {
            return;
        }
        $region = $at["region"] ?? [];
        if (!is_array($region)) {
            return;
        }
        $flags = $region["flags"] ?? [];
        if (!is_array($flags)) {
            return;
        }

        $minFood = $this->toIntFlag($flags["feed-min-hunger"] ?? null);
        $maxFood = $this->toIntFlag($flags["feed-max-hunger"] ?? null);
        if ($minFood !== null || $maxFood !== null) {
            $hunger = $player->getHungerManager();
            $food = (int) $hunger->getFood();
            $target = $food;
            if ($minFood !== null && $target < $minFood) {
                $target = $minFood;
            }
            if ($maxFood !== null && $target > $maxFood) {
                $target = $maxFood;
            }
            $target = max(0, min(20, $target));
            if ($target !== $food) {
                $hunger->setFood((float) $target);
            }
        }

        $healAmount = $this->toFloatFlag($flags["heal-amount"] ?? null);
        if ($healAmount === null || $healAmount <= 0.0) {
            return;
        }
        $healDelay = $this->toIntFlag($flags["heal-delay"] ?? null) ?? 1;
        $healDelay = max(1, $healDelay) * 20;

        $name = strtolower($player->getName());
        $now = $this->getServer()->getTick();
        $last = $this->lastHealTick[$name] ?? 0;
        if ($now - $last < $healDelay) {
            return;
        }
        $this->lastHealTick[$name] = $now;

        if ($player->getHealth() < $player->getMaxHealth()) {
            $player->heal(
                new EntityRegainHealthEvent(
                    $player,
                    $healAmount,
                    EntityRegainHealthEvent::CAUSE_MAGIC,
                ),
            );
        }
    }

    private function toIntFlag(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }
        return (int) (0 + $value);
    }

    private function toFloatFlag(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        return (float) (0 + $value);
    }

    private function hasManagePermission(
        Player $player,
        string $permission,
    ): bool {
        return $player->hasPermission("wg.admin") ||
            $player->hasPermission($permission);
    }

    /**
     * @param array<string, mixed> $region
     */
    private function canManageRegion(Player $player, array $region): bool
    {
        return $this->hasManagePermission($player, "wg.admin") ||
            $this->isMemberOrOwner($player, $region);
    }

    private function isBypass(Player $player): bool
    {
        return $player->hasPermission("wg.bypass");
    }

    /**
     * @param array<string, mixed> $region
     */
    private function isMemberOrOwner(Player $player, array $region): bool
    {
        $name = strtolower($player->getName());
        $owner = strtolower((string) ($region["owner"] ?? ""));
        if ($owner !== "" && $owner === $name) {
            return true;
        }
        $members = $region["members"] ?? [];
        return is_array($members) && in_array($name, $members, true);
    }

    private function isContainerBlock(Block $block): bool
    {
        return $this->blockNameContains($block, [
            "chest",
            "barrel",
            "hopper",
            "shulker",
            "furnace",
            "smoker",
            "blast furnace",
            "dropper",
            "dispenser",
            "brewing stand",
        ]);
    }

    private function isAccessModBlock(Block $block): bool
    {
        return $this->blockNameContains($block, [
            "door",
            "trapdoor",
            "button",
            "lever",
            "fence gate",
            "pressure plate",
            "repeater",
            "comparator",
            "note block",
        ]);
    }

    private function isIceBlock(Block $block): bool
    {
        return $this->blockNameContains($block, ["ice"]);
    }

    private function isSnowBlock(Block $block): bool
    {
        return $this->blockNameContains($block, ["snow"]);
    }

    private function isLeavesBlock(Block $block): bool
    {
        return $this->blockNameContains($block, ["leaves"]);
    }

    private function isFireBlock(Block $block): bool
    {
        return $this->blockNameContains($block, ["fire"]);
    }

    private function isWaterBlock(Block $block): bool
    {
        return $this->blockNameContains($block, ["water"]);
    }

    private function isLavaBlock(Block $block): bool
    {
        return $this->blockNameContains($block, ["lava"]);
    }

    private function isPistonBlock(Block $block): bool
    {
        return $this->blockNameContains($block, ["piston"]);
    }

    private function blockNameContains(Block $block, array $needles): bool
    {
        $name = strtolower($block->getName());
        foreach ($needles as $needle) {
            if (str_contains($name, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function isLighterItem(Item $item): bool
    {
        $name = strtolower($item->getName());
        if (
            str_contains($name, "flint and steel") ||
            str_contains($name, "fire charge")
        ) {
            return true;
        }
        return $item->getTypeId() ===
            VanillaItems::FLINT_AND_STEEL()->getTypeId();
    }

    private function isVehicleItem(Item $item): bool
    {
        $name = strtolower($item->getName());
        return str_contains($name, "boat") || str_contains($name, "minecart");
    }

    private function isVehicleEntity(Entity $entity): bool
    {
        $class = strtolower($entity::class);
        return str_contains($class, "boat") || str_contains($class, "minecart");
    }

    /**
     * @param array<string, mixed> $region
     */
    private function isPointInRegion(
        int $x,
        int $y,
        int $z,
        array $region,
    ): bool {
        $min = $region["min"] ?? null;
        $max = $region["max"] ?? null;
        if (!is_array($min) || !is_array($max)) {
            return false;
        }
        return $x >= (int) ($min["x"] ?? 0) &&
            $x <= (int) ($max["x"] ?? 0) &&
            $y >= (int) ($min["y"] ?? 0) &&
            $y <= (int) ($max["y"] ?? 0) &&
            $z >= (int) ($min["z"] ?? 0) &&
            $z <= (int) ($max["z"] ?? 0);
    }

    /**
     * @param array<string, mixed> $region
     */
    private function getRegionVolume(array $region): int
    {
        $min = $region["min"] ?? [];
        $max = $region["max"] ?? [];
        return max(
            1,
            ((int) ($max["x"] ?? 0) - (int) ($min["x"] ?? 0) + 1) *
                ((int) ($max["y"] ?? 0) - (int) ($min["y"] ?? 0) + 1) *
                ((int) ($max["z"] ?? 0) - (int) ($min["z"] ?? 0) + 1),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getRegionsAll(): array
    {
        $raw = $this->regionsConfig->get(self::DATA_REGIONS, []);
        return is_array($raw) ? $raw : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function getRegionsByWorld(string $world): array
    {
        $all = $this->getRegionsAll();
        $regions = $all[$world] ?? [];
        return is_array($regions) ? $regions : [];
    }

    /**
     * @param array<string, mixed> $regions
     */
    private function setRegionsByWorld(string $world, array $regions): void
    {
        $all = $this->getRegionsAll();
        $all[$world] = $regions;
        $this->regionsConfig->set(self::DATA_REGIONS, $all);
        $this->regionsConfig->save();
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
     * @return array{world:string,minX:int,minY:int,minZ:int,maxX:int,maxY:int,maxZ:int}|null
     */
    private function getSelection(Player $player): ?array
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

        return [
            "world" => $world,
            "minX" => min((int) $p1["x"], (int) $p2["x"]),
            "minY" => min((int) $p1["y"], (int) $p2["y"]),
            "minZ" => min((int) $p1["z"], (int) $p2["z"]),
            "maxX" => max((int) $p1["x"], (int) $p2["x"]),
            "maxY" => max((int) $p1["y"], (int) $p2["y"]),
            "maxZ" => max((int) $p1["z"], (int) $p2["z"]),
        ];
    }

    /**
     * @param array<string, mixed> $vec
     */
    private function vecText(array $vec): string
    {
        return (string) (($vec["x"] ?? 0) .
            ", " .
            ($vec["y"] ?? 0) .
            ", " .
            ($vec["z"] ?? 0));
    }

    /**
     * @param mixed $flags
     */
    private function flagsText(mixed $flags): string
    {
        if (!is_array($flags)) {
            return "-";
        }
        $parts = [];
        foreach ($flags as $k => $v) {
            if (is_array($v)) {
                $v = implode(",", $v);
            }
            $parts[] = $k . "=" . $v;
        }
        return implode("; ", $parts);
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

    private function msgRaw(CommandSender $sender, string $key): void
    {
        $prefix = (string) $this->getConfig()->getNested("messages.prefix", "");
        $text = (string) $this->getConfig()->getNested(
            "messages." . $key,
            $key,
        );
        $sender->sendMessage(TextFormat::colorize($prefix . $text));
    }
}
