module.exports = {
  rootDir: '../../..',
  testEnvironment: 'jsdom',
  testMatch: ['<rootDir>/tests/Component/WebDashboard/**/*.test.tsx'],
  setupFilesAfterEnv: ['<rootDir>/tests/Component/WebDashboard/setup.ts'],
  transform: {
    '^.+\\.[jt]sx?$': ['babel-jest', {
      presets: [
        ['@babel/preset-react', { runtime: 'automatic' }],
        ['@babel/preset-typescript', { allExtensions: true, isTSX: true }],
      ],
      plugins: ['@babel/plugin-transform-modules-commonjs'],
    }],
  },
  moduleFileExtensions: ['ts', 'tsx', 'js', 'jsx', 'json'],
};
