/** @type {import('tailwindcss').Config} */
const config = {
  // Tell Tailwind which files to scan for class names
  content: [
    "../../*.php",
    "../../admin/**/*.php",
    "../../security/**/*.php",
    "../../superadmin/**/*.php",
    "../../includes/**/*.php",
    "./**/*.js",
  ],
  theme: {
    extend: {
      backgroundImage: {
        pcu: "linear-gradient(rgba(0, 0, 0, 0.1), rgba(0, 0, 0, 0.1)), url('../../pcu-building.jpg')",
      },
    },
  },
  plugins: [],
};

// Support both Tailwind CLI (module.exports) and CDN usage (window.tailwind.config)
if (typeof module !== "undefined") {
  module.exports = config;
}

if (typeof window !== "undefined") {
  window.tailwind = window.tailwind || {};
  window.tailwind.config = config;
}