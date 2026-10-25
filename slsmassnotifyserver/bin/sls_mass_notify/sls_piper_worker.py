#!/usr/bin/python3
"""Single-threaded CPU synthesis for the admitted SLS speech process."""
import argparse
import json
import math
import sys
import wave


def session_options(runtime):
    options = runtime.SessionOptions()
    options.intra_op_num_threads = 1
    options.inter_op_num_threads = 1
    options.execution_mode = runtime.ExecutionMode.ORT_SEQUENTIAL
    options.add_session_config_entry("session.intra_op.allow_spinning", "0")
    options.add_session_config_entry("session.inter_op.allow_spinning", "0")
    return options


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--model", required=True)
    parser.add_argument("--output-file", required=True)
    parser.add_argument("--input-file")
    parser.add_argument("--length-scale", type=float)
    parser.add_argument("--noise-scale", type=float)
    parser.add_argument("--noise-w-scale", type=float)
    parser.add_argument("--sentence-silence", type=float, default=0.0)
    parser.add_argument("--volume", type=float, default=1.0)
    parser.add_argument("--speaker", type=int)
    args = parser.parse_args()
    for key, lower, upper in [
        ("length_scale", 0.1, 10),
        ("noise_scale", 0, 2),
        ("noise_w_scale", 0, 2),
        ("sentence_silence", 0, 10),
        ("volume", 0, 2),
    ]:
        value = getattr(args, key)
        if value is not None and (
            not math.isfinite(value) or not lower <= value <= upper
        ):
            parser.error("Speech parameter is outside its supported range: " + key)
    # Output is the supervisor's already-open private file, never a player or
    # an automatically selected sound device.
    if (
        not args.output_file.startswith("/proc/self/fd/")
        or not args.output_file[14:].isdigit()
    ):
        parser.error("Use the SLS speech supervisor with a private output descriptor.")
    import onnxruntime
    from piper import PiperVoice, SynthesisConfig
    from piper.config import PiperConfig

    with open(args.model + ".json", "r", encoding="utf-8") as handle:
        config = PiperConfig.from_dict(json.load(handle))
    if args.speaker is not None and not 0 <= args.speaker < config.num_speakers:
        parser.error("The selected speaker does not exist in this voice.")
    voice = PiperVoice(
        config=config,
        session=onnxruntime.InferenceSession(
            args.model,
            sess_options=session_options(onnxruntime),
            providers=["CPUExecutionProvider"],
        ),
    )
    synthesis = SynthesisConfig(
        speaker_id=args.speaker,
        length_scale=args.length_scale,
        noise_scale=args.noise_scale,
        noise_w_scale=args.noise_w_scale,
        volume=args.volume,
    )
    if args.input_file:
        source = open(args.input_file, "r", encoding="utf-8")
    else:
        source = sys.stdin
    try:
        with wave.open(args.output_file, "wb") as output:
            output.setnchannels(1)
            output.setsampwidth(2)
            output.setframerate(config.sample_rate)
            silence = bytes(round(args.sentence_silence * config.sample_rate) * 2)
            for line in iter(lambda: source.readline(65537), ""):
                if len(line.encode("utf-8")) > 65536:
                    raise ValueError("Speech input exceeds its bounded text size.")
                text = line.strip()
                if not text:
                    continue
                for index, chunk in enumerate(voice.synthesize(text, synthesis)):
                    if (
                        chunk.sample_rate != config.sample_rate
                        or chunk.sample_width != 2
                        or chunk.sample_channels != 1
                    ):
                        raise ValueError(
                            "The selected voice returned an unsupported audio format."
                        )
                    if index:
                        output.writeframes(silence)
                    output.writeframes(chunk.audio_int16_bytes)
    finally:
        if source is not sys.stdin:
            source.close()
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, ValueError, RuntimeError):
        print(
            "Speech generation failed. Verify the selected voice and its installed runtime; no playback was attempted.",
            file=sys.stderr,
        )
        raise SystemExit(74)
