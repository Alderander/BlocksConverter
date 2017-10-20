<?php

declare(strict_types=1);

namespace matcracker\BlocksConverter;

use pocketmine\block\Block;
use pocketmine\level\format\EmptySubChunk;
use pocketmine\level\Level;
use pocketmine\utils\TextFormat;

class LevelManager
{
    const IGNORE_DATA_VALUE = 99;

    /**@var Loader $loader */
    private $loader;
    /**@var Level $level */
    private $level;
    /**@var bool $converting */
    private $converting = false;

    public function __construct(Loader $loader, Level $level)
    {
        $this->loader = $loader;
        $this->level = $level;
    }

    public function getLevel() : Level
    {
        return $this->level;
    }

    public function backup() : void
    {
        $this->loader->getLogger()->debug(TextFormat::GOLD . "Creating a backup of " . $this->level->getName());
        $srcPath = $this->loader->getServer()->getDataPath() . "/worlds/" . $this->level->getFolderName();
        $destPath = $this->loader->getDataFolder() . "/backups/" . $this->level->getFolderName();
        Utils::copyDirectory($srcPath, $destPath);
        $this->loader->getLogger()->debug(TextFormat::GREEN . "Backup successfully created!");
    }

    public function restore() : void
    {
        $srcPath = $this->loader->getDataFolder() . "/backups/" . $this->level->getFolderName();
        if (!$this->hasBackup()) {
            throw new \InvalidStateException("This world never gets a backup.");
        }

        $destPath = $this->loader->getServer()->getDataPath() . "/worlds/" . $this->level->getFolderName();

        Utils::copyDirectory($srcPath, $destPath);
    }

    public function hasBackup() : bool
    {
        return file_exists($this->loader->getDataFolder() . "/backups/" . $this->level->getFolderName());
    }

    private function loadChunks(int $radius) : void
    {
        $spawn = $this->level->getSpawnLocation();
        $x = $spawn->getFloorX() >> 4;
        $z = $spawn->getFloorZ() >> 4;

        $this->loader->getLogger()->debug("Loading chunks (radius = " . $radius . ") ...");
        $chunksLoaded = 0;
        for ($chunkX = -$radius; $chunkX <= $radius; $chunkX++) {
            for ($chunkZ = -$radius; $chunkZ <= $radius; $chunkZ++) {
                if (sqrt($chunkX * $chunkX + $chunkZ * $chunkZ) <= $radius) {
                    $this->level->loadChunk($chunkX + $x, $chunkZ + $z);
                    $chunksLoaded++;
                }
            }
        }
        $this->loader->getLogger()->debug($chunksLoaded . " chunks loaded.");
    }

    public function unloadLevel() : bool
    {
        return $this->loader->getServer()->unloadLevel($this->level);
    }

    public function isConverting() : bool
    {
        return $this->converting;
    }

    private function startAnalysis() : array
    {
        $errors = [];

        if (!empty($this->loader->getBlocksData())) {
            /**@var string $blockVal */
            foreach (array_keys($this->loader->getBlocksData()) as $blockVal) {
                $blockVal = (string)$blockVal;
                $explode = explode("-", $blockVal);
                if (count($explode) !== 2) {
                    $errors[] = "$blockVal is not a correct configuration value, it should be ID-Data (e.g. 1-0)";
                }
            }
        } else {
            $errors[] = "The configuration key \"blocks\" of blocks.yml file is empty, you could not run the conversion!";
        }

        return $errors;
    }

    public function startConversion() : void
    {
        //Conversion report variables
        $status = true;
        $chunksAnalyzed = $subChunksAnalyzed = $convertedBlocks = 0;

        $time_start = microtime(true);

        /**@var string[] $errors */
        $errors = $this->startAnalysis();

        if (!empty($errors)) {
            $this->loader->getLogger()->error("Found " . count($errors) . " error(s) before starting the conversion. List:");
            foreach ($errors as $error) {
                $this->loader->getLogger()->error("- " . $error);
            }
            $status = false;
        } else {
            if (!$this->hasBackup()) {
                $this->loader->getLogger()->warning("The level " . $this->level->getName() . " will be converted without a backup.");
            }

            $this->loader->getLogger()->debug(TextFormat::GOLD . "Starting level " . $this->level->getName() . "'s conversion...");
            $this->converting = true;
            $this->loadChunks($this->loader->getChunkRadius());

            foreach ($this->level->getChunks() as $chunk) {
                $cx = $chunk->getX() << 4;
                $cz = $chunk->getZ() << 4;
                for ($y = 0; $y < $chunk->getMaxY(); $y++) {
                    $subChunk = $chunk->getSubChunk($y >> 4);
                    if (!($subChunk instanceof EmptySubChunk)) {
                        for ($x = $cx; $x < $cx + 16; $x++) {
                            for ($z = $cz; $z < $cz + 16; $z++) {
                                $blockId = $this->level->getBlockIdAt($x, $y, $z);
                                if ($blockId !== Block::AIR) {
                                    $blockData = $this->level->getBlockDataAt($x, $y, $z);
                                    foreach (array_keys($this->loader->getBlocksData()) as $blockVal) {
                                        $split = explode("-", $blockVal);
                                        $configId = (int)$split[0];
                                        $configData = (int)$split[1];

                                        if ($blockId === $configId && ($blockData === $configData || $configData === self::IGNORE_DATA_VALUE)) {
                                            $newId = (int)$this->loader->getBlocksConfig()->getNested("blocks." . $blockVal . ".converted-id");
                                            $newData = (int)$this->loader->getBlocksConfig()->getNested("blocks." . $blockVal . ".converted-data");

                                            $this->level->setBlockIdAt($x, $y, $z, $newId);
                                            $this->level->setBlockDataAt($x, $y, $z, $newData);
                                            $convertedBlocks++;
                                        }
                                    }
                                }
                            }
                        }
                        $subChunksAnalyzed++;
                    }
                }

                $chunksAnalyzed++;
            }

            $this->level->save(true);
            $this->converting = false;
            $this->loader->getLogger()->debug("Conversion finished! Printing full report...");

            $report = PHP_EOL . "§d--- Conversion Report ---" . PHP_EOL;
            $report .= "§bStatus: " . ($status ? "§2Completed" : "§cAborted") . PHP_EOL;
            $report .= "§bLevel name: §a" . $this->level->getName() . PHP_EOL;
            $report .= "§bExecution time: §a" . floor(microtime(true) - $time_start) . " second(s)" . PHP_EOL;
            $report .= "§bAnalyzed chunks: §a" . $chunksAnalyzed . PHP_EOL;
            $report .= "§bAnalyzed subchunks: §a" . $subChunksAnalyzed . PHP_EOL;
            $report .= "§bBlocks converted: §a" . $convertedBlocks . PHP_EOL;
            $report .= "§d----------";

            $this->loader->getLogger()->info(Utils::translateColors($report));
        }
    }
}