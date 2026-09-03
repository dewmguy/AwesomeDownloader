import importlib.util
import os
import tempfile
import types
import unittest
from pathlib import Path


class TranscriberTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp_dir = tempfile.TemporaryDirectory()
        cls.work_root = Path(cls.temp_dir.name).resolve()
        os.environ["TRANSCRIBER_WORK_ROOT"] = str(cls.work_root)
        module_path = Path(__file__).parents[1] / "transcriber" / "server.py"
        spec = importlib.util.spec_from_file_location("transcriber_server", module_path)
        cls.server = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.server)

    @classmethod
    def tearDownClass(cls):
        cls.temp_dir.cleanup()

    def test_work_paths_are_confined(self):
        audio = self.work_root / "sample.mp3"
        audio.write_bytes(b"audio")
        self.assertEqual(self.server.resolve_work_path(str(audio)), audio)
        with self.assertRaises(ValueError):
            self.server.resolve_work_path(str(self.work_root.parent / "outside.mp3"))
        with self.assertRaises(ValueError):
            self.server.resolve_work_path(str(self.work_root / "output.json"), output=True)

    def test_transcript_has_metadata_and_timestamps(self):
        segments = [
            types.SimpleNamespace(start=0.2, text=" Hello world. "),
            types.SimpleNamespace(start=65.9, text="Second sentence."),
        ]
        info = types.SimpleNamespace(language="en", language_probability=0.96, duration=70.0)
        result = self.server.format_transcript("sample.mp3", segments, info)
        self.assertIn("Language: en (96% confidence)", result)
        self.assertIn("[00:00:00] Hello world.", result)
        self.assertIn("[00:01:05] Second sentence.", result)


if __name__ == "__main__":
    unittest.main()
