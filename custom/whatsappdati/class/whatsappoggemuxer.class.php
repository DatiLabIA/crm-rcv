<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/whatsappoggemuxer.class.php
 * \ingroup    whatsappdati
 * \brief      Pure PHP WebM(Opus) → OGG(Opus) remuxer
 *
 * Converts WebM audio files containing Opus codec to OGG container format,
 * which is the format WhatsApp/Meta requires for voice notes.
 * This is a container remux (no re-encoding), so it's fast and lossless.
 * No external tools (ffmpeg) required.
 */

class WhatsAppOggMuxer
{
	/** @var string Raw file data */
	private $data;
	/** @var int Current read position */
	private $pos;
	/** @var int Data length */
	private $len;

	// Opus codec info (extracted from WebM CodecPrivate / OpusHead)
	/** @var string Raw OpusHead data from WebM CodecPrivate */
	private $codecPrivate = '';
	/** @var int Number of audio channels */
	private $channels = 1;
	/** @var int Input sample rate */
	private $sampleRate = 48000;
	/** @var int Pre-skip in samples at 48kHz */
	private $preSkip = 3840;
	/** @var int Audio track number in WebM (usually 1) */
	private $audioTrackNumber = 1;

	/** @var array Extracted Opus packets (raw frame data) */
	private $opusPackets = array();

	/** @var int OGG stream serial number */
	private $serialNumber;

	/** @var int Current cluster timestamp in ms (for EBML parsing) */
	private $currentClusterTimestamp = 0;

	/** @var array|null OGG CRC32 lookup table (lazy-initialized) */
	private static $crcTable = null;

	/**
	 * Convert a WebM(Opus) file to OGG(Opus)
	 *
	 * @param  string $inputPath  Path to input WebM file
	 * @param  string $outputPath Path to write OGG output
	 * @return bool   True on success, false on failure
	 */
	public static function convert($inputPath, $outputPath)
	{
		$muxer = new self();
		return $muxer->doConvert($inputPath, $outputPath);
	}

	/**
	 * Check if a file looks like a WebM with Opus audio
	 *
	 * @param  string $filePath File path to check
	 * @return bool
	 */
	public static function canConvert($filePath)
	{
		$header = @file_get_contents($filePath, false, null, 0, 64);
		if ($header === false || strlen($header) < 4) {
			return false;
		}
		// EBML header starts with 0x1A45DFA3
		return (ord($header[0]) === 0x1A && ord($header[1]) === 0x45 &&
				ord($header[2]) === 0xDF && ord($header[3]) === 0xA3);
	}

	/**
	 * Internal conversion logic
	 */
	private function doConvert($inputPath, $outputPath)
	{
		$this->data = @file_get_contents($inputPath);
		if ($this->data === false || strlen($this->data) < 10) {
			if (function_exists('dol_syslog')) {
				dol_syslog('OggMuxer: cannot read input file or too small', LOG_ERR);
			}
			return false;
		}

		$this->pos = 0;
		$this->len = strlen($this->data);
		$this->serialNumber = mt_rand(1, 0x7FFFFFFF);
		$this->opusPackets = array();
		$this->codecPrivate = '';
		$this->audioTrackNumber = 1;

		// Parse WebM/EBML to extract Opus packets
		if (!$this->parseEbml()) {
			if (function_exists('dol_syslog')) {
				dol_syslog('OggMuxer: EBML parsing failed, packets='.count($this->opusPackets), LOG_ERR);
			}
			return false;
		}

		if (empty($this->opusPackets)) {
			if (function_exists('dol_syslog')) {
				dol_syslog('OggMuxer: no Opus packets found after parsing', LOG_ERR);
			}
			return false;
		}

		if (function_exists('dol_syslog')) {
			$totalDuration = 0;
			foreach ($this->opusPackets as $pkt) {
				$totalDuration += self::getOpusFrameSamples($pkt);
			}
			$durationMs = intdiv($totalDuration, 48); // 48 samples per ms at 48kHz
			dol_syslog('OggMuxer: extracted '.count($this->opusPackets).' Opus packets, ~'.$durationMs.'ms, channels='.$this->channels.', track='.$this->audioTrackNumber, LOG_DEBUG);
		}

		// Build OGG file from extracted packets
		$ogg = $this->buildOgg();
		if (empty($ogg)) {
			return false;
		}

		$result = @file_put_contents($outputPath, $ogg);
		return ($result !== false && $result > 0);
	}


	// =========================================================================
	// EBML / WebM Parser
	// =========================================================================

	// Known EBML element IDs
	const EBML_HEADER    = 0x1A45DFA3;
	const SEGMENT        = 0x18538067;
	const SEEK_HEAD      = 0x114D9B74;
	const INFO           = 0x1549A966;
	const TRACKS         = 0x1654AE6B;
	const TRACK_ENTRY    = 0xAE;
	const TRACK_NUMBER   = 0xD7;
	const TRACK_TYPE     = 0x83;
	const CODEC_ID       = 0x86;
	const CODEC_PRIVATE  = 0x63A2;
	const CLUSTER        = 0x1F43B675;
	const TIMESTAMP      = 0xE7;
	const SIMPLE_BLOCK   = 0xA3;
	const BLOCK          = 0xA1;
	const BLOCK_GROUP    = 0xA0;
	const CUES           = 0x1C53BB6B;
	const TAGS           = 0x1254C367;

	/**
	 * Parse top-level EBML structure
	 */
	private function parseEbml()
	{
		// Verify EBML header magic
		if ($this->len < 4 || substr($this->data, 0, 4) !== "\x1A\x45\xDF\xA3") {
			return false;
		}

		// Read EBML header element
		$id = $this->readElementId();
		$size = $this->readVintSize();
		if ($id === false || $size === false) {
			return false;
		}
		// Skip EBML header content
		$this->pos += $size;

		// Now read Segment
		$id = $this->readElementId();
		$size = $this->readVintSize();
		if ($id !== self::SEGMENT || $size === false) {
			return false;
		}

		// Segment size may be "unknown" (returned as -1)
		$segmentEnd = ($size < 0) ? $this->len : min($this->pos + $size, $this->len);

		// Parse all top-level elements within Segment
		$this->parseSegment($segmentEnd);

		return !empty($this->opusPackets);
	}

	/**
	 * Parse Segment children (Tracks, Clusters, etc.)
	 * Handles Chrome's streaming WebM where Clusters have unknown size
	 */
	private function parseSegment($endPos)
	{
		while ($this->pos < $endPos) {
			$elemStart = $this->pos;
			$id = $this->readElementId();
			if ($id === false) {
				break;
			}
			$size = $this->readVintSize();
			if ($size === false) {
				break;
			}

			$dataPos = $this->pos;

			switch ($id) {
				case self::TRACKS:
					// Parse tracks to find audio track number and codec info
					$trackEnd = ($size < 0) ? $endPos : min($dataPos + $size, $endPos);
					$this->parseTracks($trackEnd);
					break;

				case self::CLUSTER:
					// Cluster may have unknown size — scan until next top-level element
					if ($size < 0) {
						// Unknown size: scan forward for cluster content
						$this->parseClusterUnknownSize($endPos);
					} else {
						$clusterEnd = min($dataPos + $size, $endPos);
						$this->parseClusterContent($clusterEnd);
					}
					break;

				case self::SEEK_HEAD:
				case self::INFO:
				case self::CUES:
				case self::TAGS:
					// Skip known container elements
					if ($size < 0) {
						// Unknown size — shouldn't happen for these, but be safe
						break;
					}
					$this->pos = $dataPos + $size;
					break;

				default:
					// Skip unknown elements
					if ($size >= 0) {
						$this->pos = $dataPos + $size;
					}
					break;
			}
		}
	}

	/**
	 * Check if an element ID is a top-level Segment child
	 */
	private function isTopLevelElement($id)
	{
		return in_array($id, array(
			self::SEEK_HEAD, self::INFO, self::TRACKS,
			self::CLUSTER, self::CUES, self::TAGS
		));
	}

	/**
	 * Parse Tracks element to find audio track and CodecPrivate
	 */
	private function parseTracks($endPos)
	{
		while ($this->pos < $endPos) {
			$id = $this->readElementId();
			if ($id === false) {
				break;
			}
			$size = $this->readVintSize();
			if ($size === false || $size < 0) {
				break;
			}
			$dataPos = $this->pos;

			if ($id === self::TRACK_ENTRY) {
				$this->parseTrackEntry($dataPos + $size);
			} else {
				$this->pos = $dataPos + $size;
			}
		}
	}

	/**
	 * Parse a single TrackEntry to extract audio track info
	 */
	private function parseTrackEntry($endPos)
	{
		$trackNumber = 0;
		$trackType = 0;
		$codecId = '';
		$codecPrivateData = '';

		while ($this->pos < $endPos) {
			$id = $this->readElementId();
			if ($id === false) {
				break;
			}
			$size = $this->readVintSize();
			if ($size === false || $size < 0) {
				break;
			}
			$dataPos = $this->pos;

			switch ($id) {
				case self::TRACK_NUMBER: // 0xD7
					$trackNumber = $this->readUint($size);
					break;

				case self::TRACK_TYPE: // 0x83
					$trackType = $this->readUint($size);
					break;

				case self::CODEC_ID: // 0x86
					$codecId = substr($this->data, $dataPos, $size);
					break;

				case self::CODEC_PRIVATE: // 0x63A2
					$codecPrivateData = substr($this->data, $dataPos, $size);
					break;

				case 0xE0: // Video settings — skip container
				case 0xE1: // Audio settings — skip container
				default:
					break;
			}
			$this->pos = $dataPos + $size;
		}

		// Track type 2 = audio
		if ($trackType === 2 && !empty($codecPrivateData)) {
			$this->audioTrackNumber = $trackNumber;
			$this->codecPrivate = $codecPrivateData;
			$this->parseOpusHead($codecPrivateData);
		}
	}

	/**
	 * Parse Cluster content (when size is known)
	 */
	private function parseClusterContent($endPos)
	{
		while ($this->pos < $endPos) {
			$id = $this->readElementId();
			if ($id === false) {
				break;
			}
			$size = $this->readVintSize();
			if ($size === false || $size < 0) {
				break;
			}
			$dataPos = $this->pos;

			if ($id === self::TIMESTAMP) {
				$this->currentClusterTimestamp = $this->readUint($size);
				$this->pos = $dataPos + $size;
			} elseif ($id === self::SIMPLE_BLOCK || $id === self::BLOCK) {
				$this->extractFramesFromBlock($dataPos, $size);
				$this->pos = $dataPos + $size;
			} elseif ($id === self::BLOCK_GROUP) {
				$this->parseBlockGroup($dataPos + $size);
			} else {
				$this->pos = $dataPos + $size;
			}
		}
	}

	/**
	 * Parse Cluster with unknown size (streaming WebM from Chrome)
	 * 
	 * Chrome's MediaRecorder writes Clusters with unknown EBML size.
	 * We parse children until we encounter the next Cluster or top-level element.
	 */
	private function parseClusterUnknownSize($fileEndPos)
	{
		while ($this->pos < $fileEndPos) {
			// Peek at the next element ID without consuming
			$savedPos = $this->pos;
			$id = $this->readElementId();
			if ($id === false) {
				break;
			}
			$size = $this->readVintSize();
			if ($size === false) {
				break;
			}
			$dataPos = $this->pos;

			// If we hit another Cluster, we've reached the end of this one
			if ($id === self::CLUSTER) {
				// New cluster — handle it
				if ($size < 0) {
					// Also unknown size — recursively process (non-recursive via loop)
					// Just continue the loop; this cluster's children will be read next
					continue;
				} else {
					$this->parseClusterContent(min($dataPos + $size, $fileEndPos));
					continue;
				}
			}

			// If we hit another top-level element, this cluster has ended
			if ($this->isTopLevelElement($id)) {
				if ($size >= 0) {
					$this->pos = $dataPos + $size;
				}
				continue;
			}

			if ($id === self::TIMESTAMP) {
				$this->currentClusterTimestamp = $this->readUint($size);
				$this->pos = $dataPos + $size;
			} elseif ($id === self::SIMPLE_BLOCK || $id === self::BLOCK) {
				$this->extractFramesFromBlock($dataPos, $size);
				$this->pos = $dataPos + $size;
			} elseif ($id === self::BLOCK_GROUP) {
				if ($size >= 0) {
					$this->parseBlockGroup($dataPos + $size);
				}
			} else {
				if ($size >= 0) {
					$this->pos = $dataPos + $size;
				} else {
					// Unknown element with unknown size — can't continue
					break;
				}
			}
		}
	}

	/**
	 * Parse a BlockGroup container
	 */
	private function parseBlockGroup($endPos)
	{
		while ($this->pos < $endPos) {
			$id = $this->readElementId();
			if ($id === false) {
				break;
			}
			$size = $this->readVintSize();
			if ($size === false || $size < 0) {
				break;
			}
			$dataPos = $this->pos;

			if ($id === self::BLOCK) {
				$this->extractFramesFromBlock($dataPos, $size);
			}
			$this->pos = $dataPos + $size;
		}
	}

	/**
	 * Extract Opus frames from a SimpleBlock or Block
	 *
	 * Block format:
	 *   - TrackNumber: EBML VINT
	 *   - Timestamp: 2 bytes signed big-endian (relative to cluster)
	 *   - Flags: 1 byte (bits 5-6: lacing mode)
	 *   - Frame data: remaining bytes
	 */
	private function extractFramesFromBlock($dataPos, $blockSize)
	{
		$saved = $this->pos;
		$this->pos = $dataPos;
		$blockEnd = $dataPos + $blockSize;

		// Track number (VINT — read the data value)
		$trackNum = $this->readVintData();
		if ($trackNum === false || $this->pos + 3 > $blockEnd) {
			$this->pos = $saved;
			return;
		}

		// Only extract frames from the audio track
		if ($trackNum !== $this->audioTrackNumber) {
			$this->pos = $saved;
			return;
		}

		// Relative timestamp (2 bytes, signed big-endian) — skip
		$this->pos += 2;

		// Flags byte
		if ($this->pos >= $blockEnd) {
			$this->pos = $saved;
			return;
		}
		$flags = ord($this->data[$this->pos]);
		$this->pos += 1;

		$lacing = ($flags >> 1) & 0x03;
		$headerLen = $this->pos - $dataPos;
		$dataLen = $blockSize - $headerLen;

		if ($dataLen <= 0) {
			$this->pos = $saved;
			return;
		}

		$frameData = substr($this->data, $this->pos, $dataLen);

		if ($lacing === 0) {
			// No lacing — single Opus frame
			if (strlen($frameData) > 0) {
				$this->opusPackets[] = $frameData;
			}
		} elseif ($lacing === 2) {
			// Fixed-size lacing
			$this->extractFixedLacedFrames($frameData);
		} elseif ($lacing === 1) {
			// Xiph lacing
			$this->extractXiphLacedFrames($frameData);
		} elseif ($lacing === 3) {
			// EBML lacing
			$this->extractEbmlLacedFrames($frameData, $dataPos + $headerLen);
		}

		$this->pos = $saved;
	}

	/**
	 * Extract frames from fixed-size lacing
	 */
	private function extractFixedLacedFrames($frameData)
	{
		if (strlen($frameData) < 1) {
			return;
		}
		$numFrames = ord($frameData[0]) + 1;
		$payloadLen = strlen($frameData) - 1;
		if ($numFrames <= 0 || $payloadLen <= 0) {
			return;
		}
		$eachSize = intdiv($payloadLen, $numFrames);
		for ($i = 0; $i < $numFrames; $i++) {
			$offset = 1 + ($i * $eachSize);
			if ($offset + $eachSize <= strlen($frameData)) {
				$this->opusPackets[] = substr($frameData, $offset, $eachSize);
			}
		}
	}

	/**
	 * Extract frames from Xiph lacing
	 */
	private function extractXiphLacedFrames($frameData)
	{
		if (strlen($frameData) < 1) {
			return;
		}
		$numFrames = ord($frameData[0]) + 1;
		$fpos = 1;
		$frameSizes = array();

		// Read sizes of first N-1 frames
		for ($i = 0; $i < $numFrames - 1; $i++) {
			$fsize = 0;
			while ($fpos < strlen($frameData)) {
				$val = ord($frameData[$fpos++]);
				$fsize += $val;
				if ($val < 255) {
					break;
				}
			}
			$frameSizes[] = $fsize;
		}

		// Extract first N-1 frames
		foreach ($frameSizes as $fs) {
			if ($fpos + $fs <= strlen($frameData)) {
				$this->opusPackets[] = substr($frameData, $fpos, $fs);
			}
			$fpos += $fs;
		}
		// Last frame: remaining data
		if ($fpos < strlen($frameData)) {
			$this->opusPackets[] = substr($frameData, $fpos);
		}
	}

	/**
	 * Extract frames from EBML lacing
	 */
	private function extractEbmlLacedFrames($frameData, $absoluteStart)
	{
		if (strlen($frameData) < 2) {
			return;
		}
		$numFrames = ord($frameData[0]) + 1;

		// Use our VINT reader by temporarily repositioning
		$savedPos = $this->pos;
		$this->pos = $absoluteStart + 1; // after frame-count byte

		$frameSizes = array();
		// First frame size (unsigned VINT)
		$firstSize = $this->readVintData();
		if ($firstSize === false) {
			$this->pos = $savedPos;
			return;
		}
		$frameSizes[] = $firstSize;

		// Subsequent sizes are signed differences
		for ($i = 1; $i < $numFrames - 1; $i++) {
			$diff = $this->readSignedVint();
			if ($diff === false) {
				break;
			}
			$frameSizes[] = $frameSizes[$i - 1] + $diff;
		}

		$fpos = $this->pos - $absoluteStart;
		$this->pos = $savedPos;

		$dataLen = strlen($frameData);
		foreach ($frameSizes as $fs) {
			if ($fs > 0 && $fpos + $fs <= $dataLen) {
				$this->opusPackets[] = substr($frameData, $fpos, $fs);
			}
			$fpos += $fs;
		}
		// Last frame: remaining data
		if ($fpos < $dataLen) {
			$this->opusPackets[] = substr($frameData, $fpos, $dataLen - $fpos);
		}
	}

	/**
	 * Parse OpusHead from CodecPrivate data
	 */
	private function parseOpusHead($data)
	{
		if (strlen($data) >= 12 && substr($data, 0, 8) === 'OpusHead') {
			$this->channels = ord($data[9]);
			$this->preSkip = unpack('v', substr($data, 10, 2))[1];
			if (strlen($data) >= 16) {
				$this->sampleRate = unpack('V', substr($data, 12, 4))[1];
			}
		}
	}


	// =========================================================================
	// EBML Primitive Readers
	// =========================================================================

	/**
	 * Read an EBML element ID (variable-length, leading bits are part of ID)
	 * @return int|false Element ID or false on EOF
	 */
	private function readElementId()
	{
		if ($this->pos >= $this->len) {
			return false;
		}

		$first = ord($this->data[$this->pos]);

		if ($first & 0x80) {
			// 1-byte ID (Class A): 1xxxxxxx
			$this->pos += 1;
			return $first;
		} elseif ($first & 0x40) {
			// 2-byte ID (Class B): 01xxxxxx xxxxxxxx
			if ($this->pos + 2 > $this->len) {
				return false;
			}
			$id = ($first << 8) | ord($this->data[$this->pos + 1]);
			$this->pos += 2;
			return $id;
		} elseif ($first & 0x20) {
			// 3-byte ID (Class C)
			if ($this->pos + 3 > $this->len) {
				return false;
			}
			$id = ($first << 16) | (ord($this->data[$this->pos + 1]) << 8) | ord($this->data[$this->pos + 2]);
			$this->pos += 3;
			return $id;
		} elseif ($first & 0x10) {
			// 4-byte ID (Class D)
			if ($this->pos + 4 > $this->len) {
				return false;
			}
			$id = ($first << 24) | (ord($this->data[$this->pos + 1]) << 16) |
				  (ord($this->data[$this->pos + 2]) << 8) | ord($this->data[$this->pos + 3]);
			$this->pos += 4;
			return $id;
		}

		return false;
	}

	/**
	 * Read an EBML VINT data size, distinguishing unknown sizes
	 * @return int Data size, or -1 for "unknown" (all data bits = 1), or false on error
	 */
	private function readVintSize()
	{
		if ($this->pos >= $this->len) {
			return false;
		}

		$startPos = $this->pos;
		$first = ord($this->data[$this->pos]);
		$width = 1;
		$mask = 0x80;

		while ($width <= 8 && !($first & $mask)) {
			$width++;
			$mask >>= 1;
		}
		if ($width > 8 || $this->pos + $width > $this->len) {
			return false;
		}

		// Mask off the length indicator bit
		$value = $first & ($mask - 1);
		$allOnes = ($mask - 1); // bits that should all be 1 for "unknown"
		$isUnknown = ($value === $allOnes);

		for ($i = 1; $i < $width; $i++) {
			$b = ord($this->data[$this->pos + $i]);
			$value = ($value << 8) | $b;
			if ($b !== 0xFF) {
				$isUnknown = false;
			}
		}
		$this->pos += $width;

		// All data bits = 1 means "unknown" size in EBML
		if ($isUnknown) {
			return -1;
		}

		return $value;
	}

	/**
	 * Read an EBML VINT data value (for non-size fields like track numbers)
	 * @return int|false Value or false on error
	 */
	private function readVintData()
	{
		if ($this->pos >= $this->len) {
			return false;
		}

		$first = ord($this->data[$this->pos]);
		$width = 1;
		$mask = 0x80;

		while ($width <= 8 && !($first & $mask)) {
			$width++;
			$mask >>= 1;
		}
		if ($width > 8 || $this->pos + $width > $this->len) {
			return false;
		}

		// Mask off the length indicator bit
		$value = $first & ($mask - 1);
		for ($i = 1; $i < $width; $i++) {
			$value = ($value << 8) | ord($this->data[$this->pos + $i]);
		}
		$this->pos += $width;

		return $value;
	}

	/**
	 * Read a signed EBML VINT (used in EBML lacing for size deltas)
	 * @return int|false Signed value or false on error
	 */
	private function readSignedVint()
	{
		if ($this->pos >= $this->len) {
			return false;
		}

		$first = ord($this->data[$this->pos]);
		$width = 1;
		$mask = 0x80;

		while ($width <= 8 && !($first & $mask)) {
			$width++;
			$mask >>= 1;
		}
		if ($width > 8 || $this->pos + $width > $this->len) {
			return false;
		}

		$value = $first & ($mask - 1);
		for ($i = 1; $i < $width; $i++) {
			$value = ($value << 8) | ord($this->data[$this->pos + $i]);
		}
		$this->pos += $width;

		// Convert to signed by subtracting bias
		$bias = (1 << ($width * 7 - 1)) - 1;
		return $value - $bias;
	}

	/**
	 * Read an unsigned integer of given byte width
	 */
	private function readUint($size)
	{
		$value = 0;
		for ($i = 0; $i < $size && ($this->pos + $i) < $this->len; $i++) {
			$value = ($value << 8) | ord($this->data[$this->pos + $i]);
		}
		return $value;
	}


	// =========================================================================
	// OGG Builder
	// =========================================================================

	/**
	 * Build complete OGG file from extracted Opus packets
	 * @return string OGG file data
	 */
	private function buildOgg()
	{
		$output = '';
		$pageSeq = 0;

		// Page 1: OpusHead (BOS = Beginning Of Stream)
		$opusHead = $this->buildOpusHead();
		$output .= $this->buildOggPage($opusHead, 0x02, 0, $pageSeq++);

		// Page 2: OpusTags
		$opusTags = $this->buildOpusTags();
		$output .= $this->buildOggPage($opusTags, 0x00, 0, $pageSeq++);

		// Data pages: group Opus packets into pages
		// OGG segment table max: 255 entries. Each packet needs ceil(size/255) + 1 entries.
		// For typical voice Opus packets (~50-200 bytes), we can fit ~40-50 per page.
		$granulePos = 0;
		$packetBuffer = '';
		$segmentTable = array();
		$packetCount = 0;
		$totalPackets = count($this->opusPackets);
		$maxSegments = 240; // Leave some room below 255 max
		$maxPacketsPerPage = 48;

		for ($i = 0; $i < $totalPackets; $i++) {
			$packet = $this->opusPackets[$i];
			$packetLen = strlen($packet);

			// Accumulate granule position (total decodeable samples)
			$samples = self::getOpusFrameSamples($packet);
			$granulePos += $samples;

			// Build segment table entries for this packet
			// OGG uses 255-byte segments; a packet ends when a segment < 255 appears
			$remaining = $packetLen;
			$newSegments = array();
			while ($remaining >= 255) {
				$newSegments[] = 255;
				$remaining -= 255;
			}
			$newSegments[] = $remaining; // Terminating segment (0-254)

			// Check if adding this packet would overflow the page
			if ($packetCount > 0 && (count($segmentTable) + count($newSegments) > $maxSegments || $packetCount >= $maxPacketsPerPage)) {
				// Flush current page
				$output .= $this->buildOggPage($packetBuffer, 0x00, $prevGranule, $pageSeq++, $segmentTable);
				$packetBuffer = '';
				$segmentTable = array();
				$packetCount = 0;
			}

			foreach ($newSegments as $s) {
				$segmentTable[] = $s;
			}
			$packetBuffer .= $packet;
			$packetCount++;
			$prevGranule = $granulePos;
		}

		// Flush last page with EOS flag
		if ($packetCount > 0) {
			$output .= $this->buildOggPage($packetBuffer, 0x04, $granulePos, $pageSeq++, $segmentTable);
		}

		return $output;
	}

	/**
	 * Build OpusHead header data
	 * @return string OpusHead binary data
	 */
	private function buildOpusHead()
	{
		// If WebM contained CodecPrivate with valid OpusHead, reuse it
		if (!empty($this->codecPrivate) && strlen($this->codecPrivate) >= 11 &&
			substr($this->codecPrivate, 0, 8) === 'OpusHead') {
			return $this->codecPrivate;
		}

		// Build OpusHead from detected parameters
		$head = 'OpusHead';              // Magic signature (8 bytes)
		$head .= chr(1);                 // Version (1)
		$head .= chr($this->channels);   // Channel count
		$head .= pack('v', $this->preSkip); // Pre-skip (16-bit LE)
		$head .= pack('V', 48000);       // Input sample rate (32-bit LE, always 48000 for Opus)
		$head .= pack('v', 0);           // Output gain (16-bit LE signed, 0 = no gain)
		$head .= chr(0);                 // Channel mapping family (0 = mono/stereo)

		return $head;
	}

	/**
	 * Build OpusTags (comment header)
	 * @return string OpusTags binary data
	 */
	private function buildOpusTags()
	{
		$vendor = 'WhatsAppDati';
		$tags = 'OpusTags';                    // Magic signature
		$tags .= pack('V', strlen($vendor));   // Vendor string length (32-bit LE)
		$tags .= $vendor;                      // Vendor string
		$tags .= pack('V', 0);                // User comment count (0)

		return $tags;
	}

	/**
	 * Build a single OGG page
	 *
	 * @param  string    $data         Page payload data
	 * @param  int       $flags        Header type flags (0x02=BOS, 0x04=EOS, 0x01=continuation)
	 * @param  int       $granulePos   Granule position
	 * @param  int       $pageSeq      Page sequence number
	 * @param  array|null $segmentTable Pre-built segment table (null = auto from data)
	 * @return string    Complete OGG page with CRC
	 */
	private function buildOggPage($data, $flags, $granulePos, $pageSeq, $segmentTable = null)
	{
		if ($segmentTable === null) {
			// Auto-generate segment table (single packet)
			$segmentTable = array();
			$remaining = strlen($data);
			while ($remaining >= 255) {
				$segmentTable[] = 255;
				$remaining -= 255;
			}
			$segmentTable[] = $remaining;
		}

		$numSegments = count($segmentTable);

		// Build page header (27 bytes + segment table)
		$header = 'OggS';                             // Capture pattern (4 bytes)
		$header .= chr(0);                             // Stream structure version (1 byte)
		$header .= chr($flags & 0xFF);                 // Header type flag (1 byte)
		// Granule position (64-bit LE) — use two 32-bit packs for compatibility
		$header .= pack('V', $granulePos & 0xFFFFFFFF);
		$header .= pack('V', ($granulePos >> 32) & 0xFFFFFFFF);
		$header .= pack('V', $this->serialNumber);     // Stream serial (32-bit LE)
		$header .= pack('V', $pageSeq);                // Page sequence number (32-bit LE)
		$header .= pack('V', 0);                       // CRC32 placeholder (32-bit LE)
		$header .= chr($numSegments);                  // Number of page segments (1 byte)

		// Segment table
		$segTableStr = '';
		foreach ($segmentTable as $s) {
			$segTableStr .= chr($s);
		}

		// Full page with CRC=0 for calculation
		$page = $header . $segTableStr . $data;

		// Calculate and insert CRC32
		$crc = self::oggCrc32($page);
		$page[22] = chr($crc & 0xFF);
		$page[23] = chr(($crc >> 8) & 0xFF);
		$page[24] = chr(($crc >> 16) & 0xFF);
		$page[25] = chr(($crc >> 24) & 0xFF);

		return $page;
	}


	// =========================================================================
	// Opus Utilities
	// =========================================================================

	/**
	 * Determine the number of PCM samples (at 48kHz) in an Opus packet
	 * by parsing the TOC byte (RFC 6716, Section 3.1)
	 *
	 * @param  string $packet Raw Opus packet
	 * @return int    Number of samples at 48kHz
	 */
	private static function getOpusFrameSamples($packet)
	{
		if (strlen($packet) < 1) {
			return 960; // Default: 20ms at 48kHz
		}

		$toc = ord($packet[0]);
		$config = ($toc >> 3) & 0x1F;
		$c = $toc & 0x03;

		// Frame duration in samples at 48kHz, indexed by config value (0-31)
		// Configs 0-11: SILK mode — durations 10/20/40/60ms
		// Configs 12-15: Hybrid mode — durations 10/20ms
		// Configs 16-31: CELT mode — durations 2.5/5/10/20ms
		static $frameSamples = array(
			480, 960, 1920, 2880,   //  0- 3: SILK NB
			480, 960, 1920, 2880,   //  4- 7: SILK MB
			480, 960, 1920, 2880,   //  8-11: SILK WB
			480, 960,               // 12-13: Hybrid SWB
			480, 960,               // 14-15: Hybrid FB
			120, 240, 480, 960,     // 16-19: CELT NB
			120, 240, 480, 960,     // 20-23: CELT WB
			120, 240, 480, 960,     // 24-27: CELT SWB
			120, 240, 480, 960,     // 28-31: CELT FB
		);

		$samples = $frameSamples[$config];

		// Code 0: 1 frame, Code 1: 2 equal frames, Code 2: 2 different frames, Code 3: arbitrary
		$frameCount = 1;
		if ($c === 1 || $c === 2) {
			$frameCount = 2;
		} elseif ($c === 3 && strlen($packet) >= 2) {
			$frameCount = ord($packet[1]) & 0x3F;
		}

		return $samples * max(1, $frameCount);
	}


	// =========================================================================
	// OGG CRC32
	// =========================================================================

	/**
	 * OGG-specific CRC32 (polynomial 0x04C11DB7, init=0, no inversion)
	 * Different from PHP's built-in crc32() which uses reflected polynomial
	 *
	 * @param  string $data Binary data to checksum
	 * @return int    32-bit CRC value
	 */
	private static function oggCrc32($data)
	{
		if (self::$crcTable === null) {
			self::$crcTable = array();
			for ($i = 0; $i < 256; $i++) {
				$r = $i << 24;
				for ($j = 0; $j < 8; $j++) {
					if ($r & 0x80000000) {
						$r = (($r << 1) & 0xFFFFFFFF) ^ 0x04C11DB7;
					} else {
						$r = ($r << 1) & 0xFFFFFFFF;
					}
				}
				self::$crcTable[$i] = $r;
			}
		}

		$crc = 0;
		$len = strlen($data);
		for ($i = 0; $i < $len; $i++) {
			$crc = (($crc << 8) & 0xFFFFFFFF) ^ self::$crcTable[(($crc >> 24) & 0xFF) ^ ord($data[$i])];
		}
		return $crc;
	}
}
