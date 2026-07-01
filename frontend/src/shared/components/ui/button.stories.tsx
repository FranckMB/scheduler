import type { Meta, StoryObj } from "@storybook/react-vite";

import { Button } from "./button";

const meta: Meta<typeof Button> = {
  title: "UI/Button",
  component: Button,
  parameters: { layout: "centered" },
};

export default meta;
type Story = StoryObj<typeof Button>;

export const Default: Story = { args: { children: "Valider" } };
export const Outline: Story = { args: { children: "Secondaire", variant: "outline" } };
export const Ghost: Story = { args: { children: "Ghost", variant: "ghost" } };
export const Destructive: Story = { args: { children: "Supprimer", variant: "destructive" } };
