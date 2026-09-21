import io
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from sync import SyncAgent, digest


class AgentTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.agent = SyncAgent('https://example.com', 't' * 40, self.tmp.name)
        self.item = {'id': 'abc', 'relative_storage_path': 'dataset/original/test.ogg', 'sha256_checksum': __import__('hashlib').sha256(b'original').hexdigest(), 'file_size_bytes': 8}

    def test_download_preserves_bytes_and_acknowledges(self):
        with patch.object(self.agent, 'request', side_effect=[io.BytesIO(b'original'), io.BytesIO()]) as request:
            self.agent.sync_one(self.item)
            path = self.agent.path(self.item['relative_storage_path'])
            self.assertEqual(path.read_bytes(), b'original')
            self.assertEqual(digest(path), self.item['sha256_checksum'])
            self.assertEqual(request.call_args.args[0], 'abc/complete')

    def test_existing_matching_file_is_not_downloaded_again(self):
        path = self.agent.path(self.item['relative_storage_path'])
        path.parent.mkdir(parents=True)
        path.write_bytes(b'original')
        with patch.object(self.agent, 'request', return_value=io.BytesIO()) as request:
            self.agent.sync_one(self.item)
            request.assert_called_once()
            self.assertEqual(request.call_args.args[0], 'abc/complete')

    def test_corrupt_existing_file_is_never_overwritten(self):
        path = self.agent.path(self.item['relative_storage_path'])
        path.parent.mkdir(parents=True)
        path.write_bytes(b'changed')
        with patch.object(self.agent, 'request') as request, self.assertRaises(RuntimeError):
            self.agent.sync_one(self.item)
        request.assert_not_called()
        self.assertEqual(path.read_bytes(), b'changed')

    def test_failed_download_is_not_acknowledged(self):
        with patch.object(self.agent, 'request', return_value=io.BytesIO(b'bad')) as request:
            with self.assertRaises(RuntimeError):
                self.agent.sync_one(self.item)
            request.assert_called_once()
        self.assertFalse(self.agent.path(self.item['relative_storage_path']).exists())
        self.assertFalse(list(Path(self.tmp.name).rglob('*.part')))

    def test_paths_cannot_escape_dataset_root(self):
        for path in ['../secret', '/secret', 'C:/secret', 'x\\secret']:
            with self.subTest(path=path), self.assertRaises(ValueError):
                self.agent.path(path)

    def test_https_required(self):
        with self.assertRaises(ValueError):
            SyncAgent('http://example.com', 't' * 40, self.tmp.name)


if __name__ == '__main__':
    unittest.main()
