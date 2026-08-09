module.exports = {
  rootDir: '../../..',
  testEnvironment: 'jsdom',
  testMatch: ['<rootDir>/tests/Component/AiAnnotations/**/*.test.ts?(x)'],
  setupFilesAfterEnv: ['<rootDir>/tests/Component/AiAnnotations/setup.ts'],
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
