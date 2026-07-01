import type { Preview } from "@storybook/react-vite";

import "../src/index.css";

const preview: Preview = {
  parameters: {
    controls: { matchers: { color: /(background|color)$/i, date: /Date$/i } },
  },
  // Render every story on the app's dark base by default.
  decorators: [
    (Story) => {
      document.documentElement.classList.add("dark");
      return Story();
    },
  ],
};

export default preview;
