"""Entry point: `tc ...` inside the web container, `ddev tc ...` from the host."""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from toolcreator.cli import main  # noqa: E402

sys.stdout.reconfigure(line_buffering=True)
sys.exit(main(sys.argv[1:]))
